# Pixelforge Image Studio

A small web app on top of the Pixelforge image-generation API. A user writes a prompt, sees what it will cost before submitting, and pays **Pixelforge's actual cost plus a 25% markup**. The upfront price is an estimate, and the real cost is settled when the image finishes. **Failures cost nothing.** Webhooks are signed, deduplicated and safe to receive out of order. If they never arrive, a poller recovers the result.

Laravel 13 · Inertia 3 · Vue 3 (official Vue starter kit with Fortify auth) · Pest 4 · Postgres 17 · Redis · Docker.

- [Key decisions](#key-decisions)
- [How a generation flows](#how-a-generation-flows)
- [Running it (Docker + make)](#running-it)
- [Tests and the TDD log](#tests-and-the-tdd-log)
- [Live smoke test](#live-smoke-test)
- [Deploying to Laravel Cloud](#deploying-to-laravel-cloud)
- [Known limits](#known-limits)

---

## Key decisions

### 1. Money is integer nanodollars

Pixelforge prices are exact 9-decimal strings (`"0.305510000"`). Every amount is stored as a `bigint` of **nanodollars** (1 USD = 1,000,000,000) and handled through `App\Support\Money`, an immutable value object:

- It parses only decimal strings. Floats, `1e3` and more than 9 decimals throw.
- Its arithmetic is integer-only. `brick/math` does the one multiplication by a ratio.
- It rounds only at the UI edge: 4 decimals, or 6 below a cent.
- The wire format between PHP and Vue is the 9-decimal string. The frontend formats it with `BigInt` and never with floats.

**Markup:** `charge = ceil(provider_cost × (10000 + MARKUP_BPS) / 10000)`. The rounding always favours the studio, and by at most one nanodollar.

### 2. A hold/settle ledger, append-only

`credit_transactions` is append-only, and `users.balance_nanos` is a cached sum of it, updated under `lockForUpdate` in the same transaction.

| Event | Rows | Balance | Available |
|---|---|---|---|
| Sign-up | `welcome +1.00` | +1.00 | +1.00 |
| Buy a pack | `purchase +N` (idempotent per checkout token) | +N | +N |
| Submit | `hold −max` (the **maximum** user price of the estimate) | – | −max |
| Success | `hold_release +max`, `charge −actual` (one transaction) | −actual | +max − actual |
| Failure | `hold_release +max` | – | +max |

- **Available** is the balance minus open holds. A submit is refused when available is below the max estimate. Because the actual cost is never above the max, the balance cannot go negative. If Pixelforge's rates change between estimate and creation, we still charge the true amount and log a warning.
- **Double charges are impossible at the database level.** A partial unique index on `(generation_id, type)` allows one hold, one release and one charge per generation. `settle` and `release` are idempotent no-ops the second time.
- **Concurrency.** Real parallel PHP processes test the invariant "cached balance = ledger sum" (`tests/Feature/Billing/LedgerConcurrencyTest.php`).

### 3. A forward-only state machine

`pending → queued → processing → succeeded | failed`. An update is applied only if it moves the status forward. Steps can be skipped, and final states are terminal. Every source of truth goes through one idempotent call, `GenerationSync::apply(ProviderGeneration)`: the POST's 202, each webhook, and the poller. So where an update came from, or in what order it arrived, never matters. A late `processing` after `succeeded` is ignored, and a second `succeeded` finds the charge already written.

### 4. `metadata.local_id` matches early webhooks

A webhook can arrive **before** our `POST /v1/generations` call has its 202, so before we know the `gen_…` id. Every submission therefore sends `metadata.local_id = <our ULID>`, which Pixelforge echoes in every webhook. `GenerationSync` matches on `local_id` first, then on `provider_id`. The ULID is also the `Idempotency-Key`, so retries of the POST can never create a second generation.

### 5. Webhooks: verify, dedupe, ack fast

`POST /webhooks/pixelforge` sits outside the web middleware group, so it has no session or CSRF.

1. The raw body is checked against `Pixelforge-Signature`: HMAC-SHA256 with `hash_equals`, any of several `v1` signatures accepted, 300 s tolerance each way. A missing secret fails closed.
2. The event is stored once with `insertOrIgnore` on the unique `evt_…` id.
3. A `ProcessWebhookEvent` job is queued and the endpoint returns 200. A duplicate gets 200 `{"duplicate": true}` and is not re-queued.

### 6. Reconcile: the fallback for missed webhooks

Delivery is "at least once, not guaranteed". `generations:reconcile` runs every minute on the scheduler (`withoutOverlapping()->onOneServer()`). It polls `GET /v1/generations/{id}` for queued or processing generations that have been quiet for more than 60 s, and feeds the result through the same `GenerationSync`. It is idempotent, so re-running it or receiving the webhook afterwards changes nothing.

### 7. Submitting politely

`SubmitGeneration` runs on the queue behind a Redis funnel: at most 3 POSTs in flight and 60 per minute, matching Pixelforge's limits.

- **Retryable errors** (429 rate or concurrency, 503 overloaded, 5xx, timeouts) release the job after `max(Retry-After, exponential backoff)`, up to 10 attempts.
- **Non-retryable errors** fail the generation and release the hold. Pixelforge's message is shown only when it is about the user's request or its capacity (content policy, validation, daily limit, overload). Operator problems such as a bad API key show a generic "unavailable, you were not charged" message and are logged as errors.

### 8. The admin report lines up with Pixelforge

Pixelforge's `/v1/usage` groups by the **UTC day of completion**, so the report does too: `(completed_at AT TIME ZONE 'UTC')::date`. Each day shows our provider cost, credit spend (what users paid), profit, and Pixelforge's billed cost, with any difference flagged to the nanodollar. Ranges over 92 days are split into several `/v1/usage` calls.

---

## How a generation flows

```
Create page ──POST /estimate (debounced)──▶ min / expected / max, can_afford
     │
     └─POST /generations ─▶ pending row + Ledger::hold(max)   (one DB transaction)
                              │
                              ▼  queue: SubmitGeneration (Redis funnel 3 / 60 per min)
                      POST /v1/generations  Idempotency-Key = ULID, metadata.local_id = ULID
                              │ 202 queued
                              ▼
     webhook ─▶ verify ─▶ webhook_events (insertOrIgnore) ─▶ queue: ProcessWebhookEvent ─┐
     poller (every minute, quiet > 60 s) ──── GET /v1/generations/{id} ──────────────────┤
     the 202 itself ─────────────────────────────────────────────────────────────────────┤
                                                                                          ▼
                                       GenerationSync::apply  (lock row, forward-only)
                                       succeeded → cost × 1.25 → Ledger::settle(charge)
                                       failed    → Ledger::release
History polls every 3 s (Inertia partial reload) until every generation is final.
```

| Area | Where |
|---|---|
| Money | `app/Support/Money.php` |
| Pixelforge HTTP client, DTOs, typed exceptions | `app/Pixelforge/` |
| Pricing: calculator, markup, estimator, cached catalog | `app/Pricing/` |
| Ledger | `app/Billing/DatabaseLedger.php` |
| Submission | `app/Http/Controllers/StoreGenerationController.php`, `app/Jobs/SubmitGeneration.php` |
| Webhooks and sync | `app/Http/Controllers/PixelforgeWebhookController.php`, `app/Http/Middleware/VerifyPixelforgeSignature.php`, `app/Jobs/ProcessWebhookEvent.php`, `app/Generations/` |
| Poller | `app/Console/Commands/ReconcileGenerations.php`, `routes/console.php` |
| Admin report | `app/Reports/`, `app/Http/Controllers/AdminReportController.php` |
| Pages | `resources/js/pages/{generations,credits,admin}/` |
| Contracts between modules | `app/Contracts/`, documented in `docs/CONTRACTS.md` |

---

## Running it

Everything runs in Docker. Nothing depends on the host's PHP, Node or database.

```bash
cp .env.example .env          # set PIXELFORGE_API_KEY / PIXELFORGE_WEBHOOK_SECRET for live calls
```

```bash
make build up install         # build the Sail 8.4 image, start the stack, composer + npm install
```

```bash
docker compose exec -u sail app php artisan key:generate
```

```bash
make fresh                    # migrate:fresh --seed → a demo user and an admin (see below)
```

The app is at `http://localhost:${APP_PORT}` (8000 by default) and Mailpit at `http://localhost:${FORWARD_MAILPIT_DASHBOARD_PORT}`. Verification emails land in Mailpit.

| Service | What it runs |
|---|---|
| `app` | `artisan serve` on :80 |
| `queue` | `queue:work redis --tries=1` (jobs set their own attempts) |
| `scheduler` | `schedule:work`, which runs `generations:reconcile` every minute |
| `vite` | HMR dev server |
| `pgsql` | Postgres 17 with `laravel` and `testing` databases |
| `redis` | queue, cache, submission funnel |
| `mailpit` | catches auth emails |
| `tunnel` (profile) | Cloudflare named tunnel → `https://prompt-edit.hondukash.dev` |

| Command | Does |
|---|---|
| `make up` / `make down` | start / stop the stack |
| `make test` | Pest (Postgres `testing` DB) and then Vitest. `ARGS="--filter=Foo"` narrows Pest. |
| `make lint` | Pint, Larastan (level 7), `vp check`, `vue-tsc` |
| `make fix` | apply Pint and frontend formatting |
| `make fresh` | `migrate:fresh --seed` |
| `make tunnel` | build assets and start the Cloudflare tunnel |
| `make shell` | bash in `app` |
| `make ci` | what GitHub Actions runs |

**Seeded accounts.** `make fresh` creates a verified demo user and an admin (`is_admin`), each with the $1.00 welcome credit. Their emails and passwords come from the `SEED_*` variables. `.env.example` holds generated development-only values. The seeder refuses to run without a password, so set your own anywhere public.

**Parallel worktrees.** Set `COMPOSE_PROJECT_NAME=prompt-edit-s<N>` and the port offsets listed in `.env.example`, so stacks never collide.

---

## Tests and the TDD log

**522 Pest tests** (about 5,900 assertions) and **57 Vitest tests in 7 files**. All run inside Docker against Postgres and Redis, the same engines as production. `lockForUpdate`, partial unique indexes and `insertOrIgnore` behave differently on SQLite, so we don't use it.

| Suite | Covers |
|---|---|
| `tests/Unit/Support`, `tests/Unit/Pricing` | Money, the cost calculator, markup, the estimator (including the docs' worked example, a max of exactly **$0.373875**, and a property test that any usage within the bounds costs between min and max), the pricing cache and fallback |
| `tests/Feature/Billing`, `Credits` | the ledger, holds and settles, idempotency, the balance invariant under real concurrent processes, purchases |
| `tests/Feature/Submission` | validation, affordability, the job: allowed fields, `Idempotency-Key`, `Retry-After`, capped retries, the Redis funnel |
| `tests/Feature/Webhooks`, `tests/Unit/Generations` | signatures, dedupe, every ordering case, the full 5×5 transition table, reconcile |
| `tests/Feature/Generations`, `resources/js/**/*.spec.ts` | Inertia props for the create, history and detail pages; estimate card, status badge, polling composable, money formatter, navigation |
| `tests/Feature/Admin` | access, per-day and per-user totals by UTC day, `/v1/usage` reconciliation, 92-day chunking |
| **`tests/Feature/EndToEnd`** | the whole app with **only real classes**, see below |

**End-to-end tests.** `tests/Feature/EndToEnd/Studio.php` drives the real app:

- registration through Fortify, including the welcome credit
- the real controllers, Ledger, pricing, `GenerationSync` and jobs (run through the real sync queue driver, so job middleware and the Redis funnel run too)
- signed webhook deliveries

Only the three edges that PLAN.md allows to be faked are faked: Pixelforge HTTP (`PixelforgeFake`, built from the docs' payloads), the queue (`Queue::fake()`, drained on demand so a test controls the order), and the clock. The scenarios:

- submit → `SubmitGeneration` → `processing` → `succeeded`: the actual cost is charged, the hold is settled, and the balance, ledger rows and pages agree
- failure after acceptance, a content-policy refusal, and an overload that is retried: $0 charged, the hold released
- a redelivered event, the same success under two event ids, **a `succeeded` processed while our POST is still waiting for its 202**, and late `processing` events after success or failure
- reconcile recovering a generation whose webhooks were all lost (and staying idempotent when the webhook shows up later)
- buying credits (with a double-click), then a storm of early, duplicate, late, lost and failed events, after which **the admin report equals the docs' `/v1/usage` fixture to the nanodollar**
- every contract in `app/Contracts` resolves to its real implementation (the `NotImplemented` stubs are gone)

**TDD.** Every behaviour started as a failing Pest or Vitest test, then got the simplest code that passed, then a refactor. Each green cycle is one commit named after the behaviour, so the history reads as a specification:

```bash
git log --reverse --no-merges --format='%s'
```

The build was split into eight sessions (`docs/SESSIONS.md`). S1 landed the contracts, test fakes and Docker stack. S2–S7 built pricing, the ledger, submission, webhooks, the UI and the admin report in parallel against those contracts. S8 removed the stubs, added the end-to-end tests, seeders and this README, and fixed what the browser QA found, each with a regression test first:

- the sidebar was missing the Credits link, the balance and the admin link
- Pixelforge's operator-facing errors (for example "invalid API key") were shown to end users

---

## Live smoke test

The QA script for the real API goes through the tunnel. Pixelforge rejects `localhost` webhook URLs.

1. In `.env`: a valid `PIXELFORGE_API_KEY`, its `PIXELFORGE_WEBHOOK_SECRET`, `CLOUDFLARE_TUNNEL_TOKEN`, and `APP_URL=https://prompt-edit.hondukash.dev`. In the Pixelforge dashboard, set the key's webhook URL to `https://prompt-edit.hondukash.dev/webhooks/pixelforge`.
2. `make tunnel`. Only one stack may run the tunnel at a time.
3. Submit prompts containing the test keywords:
   - `[succeed]`: charged the actual cost
   - `[slow]`: stays processing for a while, and History keeps polling
   - `[fail]`: $0, hold released
   - `[duplicate]`: Pixelforge redelivers, and the second delivery answers `duplicate: true` with one charge
   - also `[blocked]` (content policy, $0) and `[overloaded]` (retried)
4. Check that the Pixelforge delivery log shows 2xx, and that `/admin/report` for today shows no mismatch against `/v1/usage`.

**Status:** verified locally in the browser (Docker stack, signed webhooks sent to localhost, a webhook processed before the POST answered, live polling, detail breakdown, credits, admin report). **Not yet run against the real Pixelforge API.** The development API key in use was rejected (`401 invalid_api_key`), and no webhook secret or tunnel token was available.

---

## Deploying to Laravel Cloud

Laravel Cloud builds from the repository with its own runtime. The Docker stack is for development and CI parity, and its services mirror what Cloud needs. **Nothing has been deployed yet.** These are the steps.

1. **Application:** connect the repository, choose PHP 8.4 and Node 22.
   - Build commands:

     ```bash
     composer install --no-dev --optimize-autoloader
     ```

     ```bash
     npm ci && npm run build
     ```

   - Deploy command:

     ```bash
     php artisan migrate --force
     ```

2. **Database:** attach a Laravel Postgres database. Cloud injects `DB_*`.
3. **Cache, queue and locks:** attach a Redis-compatible key-value store (Valkey). Cloud injects `REDIS_*`. **This is required:** the submission funnel uses Redis limiters, and `QUEUE_CONNECTION=redis`.
4. **Queue worker:** add a worker cluster (or a background process) running:

   ```bash
   php artisan queue:work redis --tries=1 --timeout=60
   ```

   Jobs set their own attempts: `SubmitGeneration` allows 10.
5. **Scheduler:** turn on the scheduler for the app's compute. It runs `generations:reconcile` every minute.
6. **Environment:** see the "Production (Laravel Cloud)" block at the bottom of `.env.example`. The production deploy uses a **separate Pixelforge key** whose webhook URL is `https://<your-app>/webhooks/pixelforge`, with its own `whsec_…`.
7. **First run:** run the seeder once, with your own `SEED_*` passwords set:

   ```bash
   php artisan db:seed --force
   ```

   Then run the smoke test above against the production URL.

Proxies are already trusted (`trustProxies(at: '*')`), so URLs and cookies are `https` behind Cloud's load balancer.

---

## Known limits

- **Images are not copied.** `image_url` expires 24 h after completion. After that, History and the detail page show a placeholder. Copying to object storage is a small follow-up.
- **The daily limit (500/day) is account-wide** and shared by every user. When it is hit, users see Pixelforge's message and are not charged.
- **The funnel caps POSTs in flight, not generations in progress.** Pixelforge's "3 concurrent" may count running generations. Its `concurrency_limit_exceeded` 429 is still retried with backoff.
- **A submission that keeps getting throttled** waits about 20 minutes, over 10 attempts with exponential backoff, before failing as `submission_throttled` (no charge).
- **Generations that fail before Pixelforge accepts them** keep `completed_at` empty, so they never appear on a report day. Pixelforge doesn't bill them either.
- **`/v1/usage` is fetched on every admin report load**, with no caching. That is ⌈days / 92⌉ calls, counted against the 60 req/min limit.
- **The report compares cost, not generation counts.** A day can show the same cost with a different count and still not be flagged.
- **Purchases are a demo checkout.** Packs are credited immediately and no payment is taken.
- **The dashboard is the starter kit's placeholder.** The useful pages are New image, History, Credits and (for admins) the admin report.
- **Eloquent stores datetimes without an offset.** Every production path assigns UTC (the Pixelforge DTOs are UTC). Assigning a non-UTC Carbon would be stored shifted. Setting `$dateFormat = 'Y-m-d H:i:sP'` on the models would close this.
