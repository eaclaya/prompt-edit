# Pixelforge Image Studio: Build Plan (TDD)

Laravel 13 + Inertia + Vue 3 (official Vue starter kit with Fortify auth), Pest. Everything runs in **Docker**: development, tests and CI. Postgres and Redis are used everywhere. Deployed on Laravel Cloud.

---

## 0. Development environment (Docker)

All commands run inside containers. Nothing depends on the host's PHP, Node, Herd or SQLite.

### Stack (`compose.yaml`, built on Laravel Sail's PHP 8.4 runtime image)

| Service | Image | Purpose |
|---|---|---|
| `app` | `sail-8.4/app`, built from `docker/8.4/Dockerfile` | PHP-FPM + `artisan serve`, Composer, Node 22 |
| `queue` | same image | `php artisan queue:work redis --tries=1`. Submission and webhook jobs run here. |
| `scheduler` | same image | `php artisan schedule:work`, which runs `generations:reconcile` every minute |
| `vite` | same image | `npm run dev` with HMR, on port `${VITE_PORT:-5173}` |
| `pgsql` | `postgres:17` | The app database, plus a separate `testing` database (created by an init script) |
| `redis` | `redis:7-alpine` | Queue, cache, and the submission rate/concurrency funnel |
| `mailpit` | `axllent/mailpit` | Catches auth emails (verification, password reset) |
| `tunnel` (profile `tunnel`) | `cloudflare/cloudflared` | Runs the named Cloudflare Tunnel that serves **`https://prompt-edit.hondukash.dev`**, so Pixelforge can deliver webhooks (the docs reject localhost URLs). Started with `tunnel run --token ${CLOUDFLARE_TUNNEL_TOKEN}`. |

### Decisions

- **Postgres in dev and tests, not SQLite.** The ledger relies on `lockForUpdate`, partial unique indexes, and `insertOrIgnore` on `webhook_events`. Tests must exercise the same engine as production.
  - `phpunit.xml` points at the `testing` database on `pgsql`.
  - Tests use `RefreshDatabase` (transactions), so they stay fast.
- **Redis for queues and locks.** The 3-concurrent / 60-per-minute submission funnel uses `Redis::funnel` / `throttle`.
- **Separate `queue` and `scheduler` containers**, so local behaviour matches production: async webhooks and the reconcile poller.
- **One command surface:** a `Makefile` wraps `docker compose`.

  | Command | Runs |
  |---|---|
  | `make up` | `docker compose up -d` |
  | `make test` | Pest |
  | `make lint` | Pint + Larastan + the type-check |
  | `make shell` | a shell in `app` |
  | `make tunnel` | starts the tunnel |
  | `make fresh` | `migrate:fresh --seed` |

  `vendor/bin/sail` works too.
- **Parallel worktrees can't collide.** Each session's `.env` sets `COMPOSE_PROJECT_NAME=prompt-edit-s<N>` and offsets the forwarded ports. The table below shows S1–S3; S4–S8 continue the pattern.

  | Session | `APP_PORT` | `FORWARD_DB_PORT` | `FORWARD_REDIS_PORT` | `VITE_PORT` |
  |---|---|---|---|---|
  | S1 | 8001 | 5401 | 6301 | 5201 |
  | S2 | 8002 | 5402 | 6302 | 5202 |
  | S3 | 8003 | 5403 | 6303 | 5203 |

  Containers, volumes and networks are namespaced per project.
- **Public dev URL: `prompt-edit.hondukash.dev`, through a Cloudflare named tunnel**
  - **Ingress:** the tunnel is remote-managed in the Cloudflare dashboard. Its public hostname `prompt-edit.hondukash.dev` routes to the origin `http://app:80` over the compose network. The token goes in `.env` as `CLOUDFLARE_TUNNEL_TOKEN` and is never committed. `.env.example` has the key with an empty value.
  - **Pixelforge settings:** the dev API key's webhook URL is `https://prompt-edit.hondukash.dev/webhooks/pixelforge`, and its `whsec_…` goes in `PIXELFORGE_WEBHOOK_SECRET`. Production gets its own key, URL and secret.
  - **App settings:** when tunnelling, `APP_URL=https://prompt-edit.hondukash.dev`. Proxies are trusted (`trustProxies(at: '*')` in `bootstrap/app.php`), so Laravel sees HTTPS from Cloudflare's `X-Forwarded-*` headers and generates `https://` URLs and secure cookies.
  - **Assets:** over the tunnel, serve built assets (`npm run build`), not the Vite dev server. HMR stays on localhost.
  - **One tunnel at a time.** The hostname points at a single origin, so only one compose project runs the `tunnel` profile at once. That is normally the stack doing live webhook QA (S5, S8). Other sessions test webhooks with signed fixtures, not live deliveries.
- **CI** (GitHub Actions) runs the same compose stack: `docker compose run --rm app make ci`. Tests behave identically on a laptop and in CI.
- **Production:** Laravel Cloud builds from the repo with its own runtime, not our image. Docker is for parity in dev and CI. The compose file mirrors Cloud's services (Postgres, Redis, a worker and a scheduler) so nothing surprises us at deploy time.
  - If we ever need a Docker-based host instead, a multi-stage `docker/production/Dockerfile` (PHP-FPM + nginx, assets built in a Node stage) is a small follow-up.

---

## 1. Facts from the Pixelforge docs that drive the design

| Fact | Consequence |
|---|---|
| `POST /v1/generations` returns 202 `queued`. The result arrives by webhook or polling. | Submission and result handling are separate, async flows. |
| Supports an `Idempotency-Key` header. Keys are scoped to the account and never expire. | Every local generation gets its own key (its ULID). Retrying is always safe. |
| Accepts `metadata` (string to string), echoed in every webhook. | Send `metadata.local_id`. A webhook can arrive **before** the POST response, so we match on this, not only on `gen_…`. |
| Unknown parameters return 422. | Send only `prompt`, `size`, `quality`, `reference_images`, `metadata`. |
| The API never returns a price. Each generation carries the `rates` fixed at creation. | Store the rates on each generation. Compute cost as Σ tokens × rate / 1M. |
| Output and image-input tokens are exact upfront. Text tokens are ±15%. Reasoning tokens fall in a per-quality range. | The estimate is a range: **min / expected / max**. |
| Money is exact, 9-decimal strings. | Store integer **nanodollars** (`bigint`). Never use floats. |
| Webhooks are signed: `Pixelforge-Signature: t=…,v1=hex(HMAC-SHA256("{t}.{raw}", whsec))`. | Verify the raw body with `hash_equals`, within a 300 s tolerance. Exempt the route from CSRF. |
| Delivery is at least once, in no fixed order, and not guaranteed. | Dedupe on `evt_` id. State moves forward only. Run a fallback poller. |
| Limits: 60 req/min, 3 concurrent, 500/day. 503 `overloaded` comes with `Retry-After`. | Submission runs in a queued job with backoff that honours `Retry-After`, plus a concurrency gate. |
| A generation can fail after acceptance (`generation_failed`, `internal_error`). Failures are never billed. | On failure, release the hold and charge $0. |
| `GET /v1/usage` groups by the **UTC day of completion**. | The admin report groups by `completed_at` (UTC), so it lines up with Pixelforge. |
| `image_url` expires 24 h after completion. | Show the image while it is live, then a placeholder. (Copying to our own storage is optional.) |
| Test keywords: `[fail] [slow] [duplicate] [blocked] [overloaded] [succeed]`. | Use them in manual QA and the demo. |

---

## 2. Key decisions (to defend in the interview)

1. **Money is integer nanodollars** (`1 USD = 1_000_000_000`) in a `Money` value object, using `brick/math` for the multiplication. Pixelforge's costs are exact at 9 decimals, so nanodollars represent them with no loss.
2. **Markup:** `charge = ceil(provider_cost × 125 / 100)` in nanodollars. Rounding is always in our favour, and by at most 1 nanodollar.
3. **Display:** at the UI edge only. Amounts are shown in dollars to 4 decimal places, or 6 for sub-cent values. Stored values are never rounded.
4. **Estimate shown up front:** "≈ $X (between $min and $max)". Max uses `ceil(chars/4 × 1.15)` text tokens and the maximum reasoning tokens for the chosen quality. Rates come from `GET /v1/pricing`, cached for 1 h, with config as the fallback.
5. **Balances use a hold, then a settle:**
   - On submit, place a hold for the **max** user price. If balance minus holds is below that amount, block the submit.
   - On success, settle: charge the actual price and release the hold, in one transaction.
   - On failure, release the hold and charge $0.
   - Balances can't go negative, because the actual cost is always at or below the max. If rates change between estimate and creation, we still charge the true amount and log it.
6. **Ledger:** an append-only `credit_transactions` table. A unique index on `(generation_id, type)` makes double charges impossible at the database level. `users.balance_nanos` is a cached sum, updated under `lockForUpdate` in the same transaction.
7. **State machine:** the local statuses are `pending → queued → processing → succeeded | failed`. An update is applied only if it moves the status forward. Final states are terminal. In the UI, `pending` is shown as "queued".
8. **Every event source goes through one path.** Webhooks, the poller and the submit response all call `GenerationSync::apply(array $providerGeneration)`. That call is idempotent, so where the data came from doesn't matter.

---

## 3. Data model

```
users                 + is_admin bool, balance_nanos bigint default 0
generations           id (ulid), user_id, provider_id (gen_…, nullable, unique),
                      idempotency_key (unique), prompt, size, quality, reference_images json,
                      status, error_code, error_message,
                      rates json, est_min/est_expected/est_max_nanos (user price),
                      usage json, provider_cost_nanos, charged_nanos,
                      image_url, image_url_expires_at,
                      submitted_at, started_at, completed_at, timestamps
webhook_events        id, event_id (unique evt_…), type, generation_provider_id,
                      payload json, received_at, processed_at
credit_transactions   id, user_id, generation_id nullable, type
                      (purchase|welcome|hold|hold_release|charge), amount_nanos (signed),
                      balance_after_nanos, meta json, timestamps
                      UNIQUE(generation_id, type) where generation_id not null
```

---

## 4. Backend components

- `app/Support/Money.php`: the nanodollar value object (parse a decimal string, add, multiply by a rational, format).
- `app/Pricing/`
  - `PricingCatalog` fetches and caches `/v1/pricing`.
  - `CostCalculator` turns usage and rates into provider nanodollars.
  - `Markup` applies ×1.25 with ceil.
  - `CostEstimator` returns min/expected/max for a request.
- `app/Pixelforge/PixelforgeClient.php`: a thin HTTP client (Bearer token, timeouts). It maps errors to typed exceptions: `RateLimited(retryAfter)`, `ConcurrencyLimited`, `DailyLimit`, `Overloaded(retryAfter)`, `ContentPolicy`, `Validation`.
- `app/Jobs/SubmitGeneration.php`:
  - Sends the POST with the Idempotency-Key, then `GenerationSync::apply()` on the response.
  - Retryable errors release the job back to the queue after `Retry-After`. Use capped attempts and exponential backoff, with a `RateLimited` / `WithoutOverlapping` funnel set to 3 concurrent and 60/min.
  - Non-retryable errors (content policy, validation, daily limit) mark the generation `failed` and release the hold.
- `app/Http/Controllers/PixelforgeWebhookController.php`:
  1. Verify the signature against the raw body.
  2. `insertOrIgnore` into `webhook_events`.
  3. If the event is new, dispatch `ProcessWebhookEvent`.
  4. Return 200 quickly.
  - A bad signature returns 400. A `ping` returns 200.
- `app/Generations/GenerationSync.php`:
  1. Find the generation by `metadata.local_id`, or by `provider_id`.
  2. In a database transaction, lock the row.
  3. Apply the update only if the status moves forward. Fill `started_at` / `completed_at`.
  4. On success, compute and settle the cost. On failure, release the hold.
- `app/Console/ReconcileGenerations`: a scheduled command that runs every minute. It polls non-final generations that haven't been updated in more than about 60 s. This is the fallback for missed webhooks.
- `EstimateController`: `POST /estimate`, returning JSON with debounced live pricing. The same calculation also runs server-side on submit.
- Fake purchases: `POST /credits/purchase` with packs of $5, $10 and $25. Uses an idempotency token so a double-click is safe.

---

## 5. Frontend (Inertia + Vue)

- **`generations/Create`**: prompt (max 4000, with a char counter), size select, quality select, and up to 4 reference URL inputs. A live estimate card shows expected, the range, and the current balance. Submit is disabled when the user can't afford the max, with a link to Buy credits.
- **`generations/Index`** (history): a card grid with a status badge, prompt, size/quality, estimated vs actual cost, image or placeholder, and an error message if it failed. While any item is non-final, it refreshes with `router.reload({ only: ['generations'] })` on an interval (Inertia polling). Paginated.
- **`generations/Show`**: the image, a token usage breakdown, rates, and the cost math.
- **`credits/Index`**: the balance, pack buttons, and the ledger.
- **`admin/Report`** (behind an `is_admin` gate):
  - Filters by date range.
  - A per-day table showing provider cost, credit spend, profit, and Pixelforge `/v1/usage` cost, with the difference highlighted.
  - A per-user table for the same range.
- The sidebar balance is shared through `HandleInertiaRequests`.

---

## 6. How we work: test-driven development

Every behaviour is written as a failing Pest test first. Code is written only to make a red test pass.

### The cycle (for each item in a phase's test list)

1. **Red.** Write one test for the next behaviour. Run it and watch it fail *for the right reason*: an assertion failure, not a typo or a missing import.
2. **Green.** Write the simplest code that makes the test pass. Nothing speculative.
3. **Refactor.** With the suite green, clean up names, duplication and structure. Run the suite again.
4. Run `make test` (and `make lint` for frontend phases) before each commit. Both run inside Docker against Postgres.

### Rules

- **No production code without a failing test that needs it.** That includes migrations, routes and Vue pages: a feature test that hits the route or asserts the Inertia component comes first.
- **Build from the outside in, one layer at a time.**
  - Feature tests describe the HTTP behaviour: routes, Inertia props, redirects, and database state.
  - Unit tests drive the pure domain logic underneath: `Money`, the pricing classes, and the state-transition rules.
- **Don't mock what you own.** Fake only the edges:
  - `Http::fake()` for Pixelforge
  - `Queue::fake()` / `Bus::fake()` when asserting dispatch
  - `Carbon::setTestNow()` for time
  - `RefreshDatabase` for the database
- **Fixtures come from the docs.** Pixelforge's documented payloads (the generation object, the webhook body, the pricing and usage responses, the error bodies) live in `tests/Fixtures/pixelforge/*.json`. Tests build on them, so they match the real API's shape.
- **Every bug gets a regression test first.** Reproduce it as a failing test before fixing it.
- **One commit per green cycle, or a small group of them.** Commit messages name the behaviour, e.g. "Duplicate webhook event is recorded once".
- **CI** (GitHub Actions) runs Pest, Pint, Larastan and the frontend type-check on every push.

### Test helpers to build first (Phase 0)

- `tests/Fixtures/pixelforge/` with the JSON examples copied from the docs.
- `PixelforgeFake`: a helper that registers `Http::fake` responses: `->acceptsGeneration()`, `->failsWith('overloaded', retryAfter: 2)`, `->returnsUsage([...])`.
- `signedWebhook(array $payload, ?int $timestamp = null)`: builds the body and a valid `Pixelforge-Signature` header for posting to the webhook route.
- Pest expectations: `toEqualMoney('0.373875')` and `toHaveBalance('1.00')`.

---

## 7. Build phases (each is a sequence of red → green → refactor cycles)

The test lists below are written **in the order they should be written**. Each bullet is one red test.

### Phase 0: Test harness and config
- `config('pixelforge.*')` reads the base URL, API key, webhook secret, tolerance and markup from env.
- The fixture loader returns each docs payload.
- The `signedWebhook()` helper produces a signature that a reference HMAC check accepts.
- **Docker comes first, then the tests:**
  - `compose.yaml`: `app`, `queue`, `scheduler`, `vite`, `pgsql` (with the `testing` database), `redis` and `mailpit`, plus a `tunnel` profile
  - the `Makefile`
  - `phpunit.xml` pointed at Postgres
- Smoke test: the database connection under test is `pgsql`, and the queue is `redis` outside tests.
- CI workflow runs `make ci` inside the compose stack: Pest, Pint, Larastan, and the type-check.

**Done:** the harness is green and CI passes on the clean scaffold.

### Phase 1: Money and pricing core (unit tests)
**`Money`**
- Parses `"0.046125000"` to 46,125,000 nanodollars, and formats it back exactly.
- Rejects floats and more than 9 decimal places.
- `add`, `subtract` and `compare` work, and the value is immutable.
- `multiplyRatio(125, 100)` rounds **up** (ceil) when the result isn't a whole nanodollar.
- Formats for display (4 or 6 decimals) without changing the stored value.

**`CostCalculator`**
- usage × rates → the docs' example usage (12 + 765 + 6450 + 3184 tokens) gives the exact expected cost.
- Missing or unknown token types fail loudly.

**`Markup`**
- 25% applied to the provider cost, with ceil rounding.
- Zero stays zero.

**`CostEstimator`**
- Output tokens come from the quality × size table.
- Each reference image adds 765 image tokens.
- Text tokens are `ceil(chars / 4)`, counting Unicode characters, not bytes.
- Max uses ×1.15 text tokens and the maximum reasoning tokens. **The docs' worked example gives a max of exactly $0.373875.**
- Min uses ×0.85 text tokens and the minimum reasoning tokens. Min ≤ expected ≤ max.
- Property test: random actual usage within the bounds always costs between min and max.

**`PricingCatalog`**
- Reads `/v1/pricing` (faked) and caches it for 1 h.
- Falls back to config when the endpoint fails.

### Phase 2: Schema and ledger (feature tests with the database)
- A new user starts with a $1.00 welcome credit and one `welcome` ledger row.
- `Ledger::purchase` adds credit and records `balance_after`.
- `Ledger::hold` reserves an amount. It throws `InsufficientCredits` when available (balance minus holds) is too low.
- `Ledger::settle` charges the actual amount and releases the hold in one transaction.
- `Ledger::release` returns the hold, with a charge of $0.
- Settling the same generation twice records one charge, enforced by the unique index (assert the second call is a no-op).
- Two concurrent settles produce one charge (simulated with `lockForUpdate`).
- The cached `users.balance_nanos` always equals the sum of ledger rows (an invariant test after each operation).

### Phase 3: Submitting a generation
**HTTP (feature tests)**
- A guest is redirected to login.
- Validation: prompt is required and at most 4000 characters, size and quality must be in their enums, at most 4 http(s) reference URLs. A 422 returns field errors.
- `POST /estimate` returns min, expected and max for the given input.
- A submit the user can't afford is rejected and nothing is created.
- A valid submit creates a `pending` generation, places a hold for the max, dispatches `SubmitGeneration`, and redirects to history.

**`SubmitGeneration` job**
- Sends only the allowed fields, with an `Idempotency-Key` header and `metadata.local_id`.
- A 202 response stores `provider_id` and the rates, and sets the status to `queued`.
- A 202 with `Idempotent-Replayed: true` doesn't change the hold or create anything new.
- 429 `rate_limit_exceeded`, 429 `concurrency_limit_exceeded` and 503 `overloaded` release the job back to the queue after `Retry-After`. Retries are capped, and the job fails cleanly once the cap is hit.
- 400 `content_policy_violation`, 422 and 429 `daily_limit_exceeded` mark the generation `failed` with the error and release the hold.
- If the webhook already moved the generation forward, the POST response doesn't move it back.

### Phase 4: Webhooks and state sync
**Signature (feature tests on `POST /webhooks/pixelforge`)**
- A missing, malformed or wrong signature returns 400.
- A timestamp more than 300 s off returns 400.
- A valid signature returns 200, even without a CSRF token.
- `ping` returns 200 and changes nothing.

**Idempotency and ordering**
- The same `evt_` delivered twice is stored once and processed once.
- `processing → succeeded` produces a final `succeeded` with one charge.
- `succeeded` followed by a late `processing` stays `succeeded`.
- `failed` followed by a late `processing` stays `failed`, with $0 charged and the hold released.
- `succeeded` arriving before the POST response is matched by `metadata.local_id`.
- An event for an unknown generation is stored and acknowledged without erroring.
- On success, usage, `image_url`, the expiry time, `provider_cost`, the charge and `completed_at` are all stored.

**`GenerationSync` (unit tests on the transition rules)**
- An allowed-transition table test covers every pair of statuses.

**`generations:reconcile` command**
- Polls only non-final generations that have gone quiet. Applies the same sync, and is idempotent when re-run.
- Scheduled every minute (assert on the schedule).

### Phase 5: UI (create, history, detail)
**Feature tests (Inertia assertions)**
- `generations/Create` receives the sizes, qualities and balance.
- `generations/Index` receives only the user's own generations, paginated, with estimated and actual cost and status.
- `generations/Show` returns 404 for another user's generation.
- An expired `image_url` is sent with an `expired` flag.

**Frontend**
- Vue component tests (Vitest + Vue Test Utils) for:
  - the estimate card (formatting and range)
  - the status badge
  - the polling composable, which stops when every generation is final
- Manual QA script using `[slow]`, `[fail]` and `[duplicate]` through the Cloudflare tunnel at `prompt-edit.hondukash.dev`.

### Phase 6: Balances UI (stretch)
- `POST /credits/purchase` with a valid pack adds credit. An invalid pack returns 422. A duplicate token (double-click) credits once.
- `credits/Index` shows the balance and the ledger, newest first.
- The sidebar balance prop is shared on every page.
- Submit is blocked in the UI when the balance is below the max estimate (component test).

### Phase 7: Admin report (stretch)
- Non-admins get 403. Admins get 200.
- The per-day totals for provider cost, credit spend and profit match the seeded generations, grouped by the UTC day of `completed_at`.
- The per-user totals for a date range are correct.
- The `/v1/usage` fixture is compared to our totals, and differences are flagged per day.
- Ranges over 92 days are split into several usage calls.

### Phase 8: Deploy
- Smoke test against production with a separate API key:
  - submit → webhook → charge
  - the webhook delivery log in Pixelforge shows 2xx
  - the admin report matches `/v1/usage`
- Laravel Cloud runs a queue worker and the scheduler, matching the `queue` and `scheduler` containers. The production `.env` is documented in `.env.example`.
- Before deploying, a full rehearsal runs locally: `make up` plus `make tunnel`, with the dev Pixelforge key's webhook URL set to `https://prompt-edit.hondukash.dev/webhooks/pixelforge`. Pixelforge's delivery log should show 2xx.

### Phase 9: Polish and README
- README covering the decisions, how to run the tests, the TDD log (the commit history reads as specs), and known limits.

The core requirements are phases 0–5 plus 8. The stretch goals are phases 6–7, built only once the core works.

---

## 8. Open questions / assumptions

- **Welcome credit:** new users get **$1.00** so they can try the app without buying first.
- **Image retention:** we don't copy images to our own storage, which the brief allows. Copying to S3 is an easy follow-up.
- **Daily limit (500/day):** this is account-wide, so it is shared by all users. The user sees a clear error and isn't charged.
- **Admin access:** an `is_admin` flag, set by the seeder for the operator account.

---

## 9. Session split

See [SESSIONS.md](SESSIONS.md). S1 lands the contracts first. After that, S2–S7 run in parallel, each in its own worktree. S8 then integrates and deploys.
