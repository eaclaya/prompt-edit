# Pixelforge Image Studio

A small web app on top of the Pixelforge image-generation API. A user writes a prompt, sees what it will cost before submitting, and pays **Pixelforge's actual cost plus a 25% markup**. The upfront price is an estimate, and the real cost is settled when the image finishes. **Failures cost nothing.** Webhooks are signed, deduplicated and safe to receive out of order. If they never arrive, a poller recovers the result.

Laravel 13 · Inertia 3 · Vue 3 (official Vue starter kit with Fortify auth) · Pest 4 · Postgres 17 · Redis · Docker.

- [For reviewers](#for-reviewers)
- [Key decisions](#key-decisions)
- [How a generation flows](#how-a-generation-flows)
- [Running it (Docker + make)](#running-it)
- [Tests and the TDD log](#tests-and-the-tdd-log)
- [Live smoke test](#live-smoke-test)
- [Hosting (Cloudflare tunnel)](#hosting-cloudflare-tunnel)
- [Known limits](#known-limits)

---

## For reviewers

**Live app: <https://prompt-edit.hondukash.dev>**

| Account | Email | Password | What you can see |
|---|---|---|---|
| Regular user | `demo@pixelforge.test` | `pixelforge-demo-2026` | New image, History (already has examples), Credits |
| Admin | `admin@pixelforge.test` | `pixelforge-admin-2026` | Everything above, plus **Admin report** in the sidebar (log out first: the login form is pre-filled with the regular user) |

You can also **sign up with any email**. There is no email verification, and a new account gets a **$2.00 welcome credit** on the live site. The two accounts above are shared, so other reviewers see the same history and balance.

### A five-minute tour

1. **Log in as the regular user.** Opening the site takes you to the login page with this account **already filled in**: just press *Log in*. You land on **History**, which already has example generations:
   - three successes: a high-quality landscape, a `[slow]` portrait, and a `[duplicate]` whose webhook was delivered twice but charged once
   - a `[fail]` and a `[blocked]` (content-policy refusal), both charged **$0**
   - each card shows the **estimated range** and the **actual charge**
2. **Open a generation.** The detail page shows the image, the estimate you saw upfront, and the cost breakdown: Pixelforge's token usage × its rates, the provider cost, then **+25%**, rounded up.
3. **Make one.** Go to **New image** and type a prompt. The estimate (min – expected – max) updates as you type and as you change size, quality or reference images. Click **Generate**: the **maximum** is held from the balance, and History updates live through *Queued → Processing → Succeeded/Failed*. That takes about 10–90 s.
4. **Credits.** The balance and every ledger row: the hold at submit, then the charge for the actual cost plus the hold's release (a failure only releases). **Buy credits** is a fake checkout that adds credit instantly.
5. **Log out and log in as the admin.** **Admin report** shows, per UTC day, Pixelforge's cost, what users were charged, and the profit, next to Pixelforge's own `GET /v1/usage`. It also has per-user totals for the date range.

### Test keywords

Put one in the prompt to steer Pixelforge's test behaviour:

| Keyword | What happens |
|---|---|
| `[succeed]` | succeeds quickly; charged the actual cost |
| `[slow]` | stays *Processing* for about a minute, so you can watch History update live |
| `[fail]` | fails after processing; **$0**, hold released |
| `[blocked]` | refused by the content policy; **$0** |
| `[duplicate]` | Pixelforge delivers the webhook twice; it is stored and charged once |
| `[overloaded]` | Pixelforge answers 503 at first; the job waits for `Retry-After` and retries |

### Good to know

- **Real money, small amounts.** Each generation is billed to our Pixelforge key: about $0.01 for a low-quality square up to about $0.45 for a high-quality landscape. Pixelforge allows 500 generations a day across all users.
- **Amounts** are shown to 4 decimals, but stored and charged to the nanodollar (1e-9 USD).
- **The admin report flags 2026-11-23.** Before launch, three live smoke-test generations ($0.1852375) were recorded in a separate development database that used the same Pixelforge key. Pixelforge billed them, but this database doesn't have them, so the report flags that day. That is the reconciliation working.
- **Hosting.** The site runs on a developer machine behind a Cloudflare tunnel (see [Hosting](#hosting-cloudflare-tunnel)). If it doesn't load, that machine is probably asleep; let us know.
- **No outgoing email.** Password-reset emails don't leave the server, so please use the accounts above (or sign up again).
- **Images** are copied to our own storage when a generation succeeds, so they keep showing after Pixelforge's 24-hour link expires.
- **Reading the code:** start with [Key decisions](#key-decisions) and [How a generation flows](#how-a-generation-flows). The commit history reads as the test-driven spec ([Tests and the TDD log](#tests-and-the-tdd-log)). To run it yourself, see [Running it](#running-it).

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
                                                   → queue: StoreGenerationImage (our copy of the image)
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
| Image copies | `app/Jobs/StoreGenerationImage.php` (public disk, `storage/app/public/generations`) |
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
| `tunnel` (profile, optional) | token-based cloudflared, an alternative to the host tunnel described under [Hosting](#hosting-cloudflare-tunnel) |

| Command | Does |
|---|---|
| `make up` / `make down` | start / stop the stack |
| `make test` | Pest (Postgres `testing` DB) and then Vitest. `ARGS="--filter=Foo"` narrows Pest. |
| `make lint` | Pint, Larastan (level 7), `vp check`, `vue-tsc` |
| `make fix` | apply Pint and frontend formatting |
| `make fresh` | `migrate:fresh --seed` |
| `make tunnel` | stop Vite, build assets, and start the optional `tunnel` container (needs `CLOUDFLARE_TUNNEL_TOKEN`) |
| `make shell` | bash in `app` |
| `make ci` | what GitHub Actions runs |

**Seeded accounts.** `make fresh` (or `php artisan db:seed`) creates the demo user and the admin (`is_admin`) from the `SEED_*` variables, each with the welcome credit. `.env.example` has the same reviewer credentials published [above](#for-reviewers). Re-running the seeder updates their names and passwords, never their credit. It refuses to run without a password.

**Parallel worktrees.** Set `COMPOSE_PROJECT_NAME=prompt-edit-s<N>` and the port offsets listed in `.env.example`, so stacks never collide.

---

## Tests and the TDD log

**519 Pest tests** (about 6,000 assertions; 9 more are the starter kit's email-verification tests, which skip themselves now that verification is off) and **57 Vitest tests in 7 files**. All run inside Docker against Postgres and Redis, the same engines the public stack uses. `lockForUpdate`, partial unique indexes and `insertOrIgnore` behave differently on SQLite, so we don't use it.

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

1. In the `.env` of the stack the tunnel points at: a valid `PIXELFORGE_API_KEY`, its `PIXELFORGE_WEBHOOK_SECRET`, and `APP_URL=https://prompt-edit.hondukash.dev`. In the Pixelforge dashboard, set the key's webhook URL to `https://prompt-edit.hondukash.dev/webhooks/pixelforge`.
2. Serve built assets and route the tunnel to that stack, as described under [Hosting](#hosting-cloudflare-tunnel).
3. Submit prompts containing the test keywords:
   - `[succeed]`: charged the actual cost
   - `[slow]`: stays processing for a while, and History keeps polling
   - `[fail]`: $0, hold released
   - `[duplicate]`: Pixelforge redelivers, and the second delivery answers `duplicate: true` with one charge
   - also `[blocked]` (content policy, $0) and `[overloaded]` (retried)
4. Check that the Pixelforge delivery log shows 2xx, and that `/admin/report` for today shows no mismatch against `/v1/usage`.

**Status: run against the real Pixelforge API on 2026-11-23.** Webhooks were delivered through the tunnel to the local Docker stack. Four medium 1024×1024 prompts were submitted (hold of about $0.0863 each):

| Prompt | Final | Pixelforge cost | Charged (+25%, ceil) | Events stored |
|---|---|---|---|---|
| `[succeed]` | succeeded | $0.049630000 | $0.062037500 | processing, succeeded |
| `[slow]` | succeeded after about 80 s, with History polling the whole time | $0.067935000 | $0.084918750 | processing, succeeded |
| `[fail]` | failed (`generation_failed`), Pixelforge's message shown | $0 | $0 | processing, failed |
| `[duplicate]` | succeeded | $0.067672500 | $0.084590625 | processing, succeeded (the redelivery 3 s later was deduplicated) |

- **Ledger:** 4 holds, 4 hold releases, 3 charges. The balance is $1.00 − $0.231546875 = $0.768453125, nothing is left held, and no webhook event is left unprocessed.
- **Webhooks:** 13 signed deliveries arrived during the run and 8 events were stored. The extra deliveries were deduplicated on `evt_…`, and none led to a second charge.
- **Retries:** with four submitted at once, two POSTs were released and retried by the job (Pixelforge's concurrency limit), then accepted.
- **Reconciliation:** `/v1/usage` for the day reports 3 generations and $0.185237500, which equals our provider cost for those three to the nanodollar. The admin report on that dev database also flags +$0.059070. That is a synthetic generation from the earlier local QA, completed with hand-signed webhooks, which Pixelforge never billed. The report is right to flag it.
- **Not checked:** Pixelforge's own delivery log; we saw only our side of the deliveries.

---

## Hosting (Cloudflare tunnel)

The app is served publicly at **`https://prompt-edit.hondukash.dev`** from the Docker stack in the main checkout, through a Cloudflare tunnel. There is no separate cloud deploy.

**How the URL reaches the app**

- A **host-managed `cloudflared` tunnel** (`hondukash-dev`, run as a launchd service) owns the hostname. Its ingress rule in `~/.cloudflared/config.yml` points the hostname at `http://localhost:8010`, which is this stack's `app` container (`APP_PORT=8010`, `COMPOSE_PROJECT_NAME=prompt-edit`).
- Cloudflare terminates TLS. Proxies are trusted (`trustProxies(at: '*')`), so Laravel sees `https` and builds correct URLs and cookies.
- The compose `tunnel` profile (`make tunnel`, token-based) is an alternative for machines without the host tunnel. Never run both for the same hostname.

**Requirements for the served stack**

- **Its own `.env`** must hold the real `PIXELFORGE_API_KEY` and `PIXELFORGE_WEBHOOK_SECRET`, and `APP_URL=https://prompt-edit.hondukash.dev`. Each worktree has its own `.env`. A stack with an empty or stale secret answers Pixelforge's deliveries with `400 invalid_signature`.
- **Built assets, not Vite.** Stop the `vite` container, remove `public/hot`, and run `npm run build`. Over https, the browser blocks the dev server's `http://localhost` assets.
- **The `queue` and `scheduler` containers must be running.** Webhooks are processed on the queue, and the scheduler runs `generations:reconcile` every minute.
- After changing `.env`: `php artisan config:clear`, then `docker compose restart queue scheduler`.

**Bringing it up**

```bash
docker compose up -d app queue scheduler pgsql redis mailpit
```

```bash
make install
```

```bash
rm -f public/hot && docker compose exec -u sail app npm run build
```

```bash
docker compose exec -u sail app php artisan migrate --seed --force
```

```bash
docker compose exec -u sail app php artisan storage:link
```

**Checking it**

- `https://prompt-edit.hondukash.dev/login` loads with `/build/assets/…` scripts.
- An unsigned `POST /webhooks/pixelforge` returns `400`. A ping signed with the secret returns `200 {"received":true}`.
- A test event sent from the Pixelforge dashboard shows as delivered (2xx).

**Limits of tunnel hosting.** The site is up only while this machine, Docker and the tunnel service are running. There is no zero-downtime deploy: rebuild the assets and restart the containers to ship a change.

---

## Known limits

- **Image copies live on this machine's disk** (`storage/app/public`). A copy is made when a generation succeeds; if the download fails three times, the page keeps Pixelforge's link, which expires after 24 h. Object storage (S3/R2) would be the next step for a real host.
- **No email is sent.** Verification is off (there is no mail service behind the site), and password-reset emails stay in the local Mailpit.
- **The daily limit (500/day) is account-wide** and shared by every user. When it is hit, users see Pixelforge's message and are not charged.
- **The funnel caps POSTs in flight, not generations in progress.** Pixelforge's "3 concurrent" may count running generations. Its `concurrency_limit_exceeded` 429 is still retried with backoff.
- **A submission that keeps getting throttled** waits about 20 minutes, over 10 attempts with exponential backoff, before failing as `submission_throttled` (no charge).
- **Generations that fail before Pixelforge accepts them** keep `completed_at` empty, so they never appear on a report day. Pixelforge doesn't bill them either.
- **`/v1/usage` is fetched on every admin report load**, with no caching. That is ⌈days / 92⌉ calls, counted against the 60 req/min limit.
- **The report compares cost, not generation counts.** A day can show the same cost with a different count and still not be flagged.
- **Purchases are a demo checkout.** Packs are credited immediately and no payment is taken.
- **The dashboard is the starter kit's placeholder.** The useful pages are New image, History, Credits and (for admins) the admin report.
- **Eloquent stores datetimes without an offset.** Every production path assigns UTC (the Pixelforge DTOs are UTC). Assigning a non-UTC Carbon would be stored shifted. Setting `$dateFormat = 'Y-m-d H:i:sP'` on the models would close this.
