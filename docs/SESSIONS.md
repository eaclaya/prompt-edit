# Work split: independent sessions

The build is split into **8 sessions**. Each runs in its own git worktree and branch, and owns a disjoint set of files.

Sessions stay independent because **Session 1 lands the shared contracts first**: interfaces, schema, routes, Inertia prop types, fixtures and test fakes. After that, Sessions 2–7 run in parallel and code only against those contracts. Session 8 integrates and deploys.

Every session follows the TDD rules in [PLAN.md §6](PLAN.md). Each one reads `PLAN.md` and its own brief below, and nothing else from other sessions.

```
S1 Foundation & contracts ──┬── S2 Pricing engine
  (merge first)             ├── S3 Ledger & credits
                            ├── S4 Submission
                            ├── S5 Webhooks & sync
                            ├── S6 Generations UI
                            └── S7 Admin report
                                     │
                      all merged ────┴── S8 Integration & deploy
```

---

## Ground rules for every session

1. **Stay inside your owned paths** (listed below). If you need a change to a contract, schema or route that Session 1 owns, **don't make it yourself**. Write it in `docs/contract-requests/<session>.md` and stop to ask.
2. **Code against interfaces in `app/Contracts/`, never against another session's concrete class.** In tests, bind the in-memory fakes from `tests/Fakes/` that Session 1 provides. Session 8 replaces them with real wiring and end-to-end tests.
3. **The service provider is the only shared file:**
   - Each session registers its bindings in **its own** provider (e.g. `App\Providers\PricingServiceProvider`).
   - It appends one line to `bootstrap/providers.php`, which is the only expected merge conflict, and it's trivial.
   - Until a binding is registered, the contract resolves to Session 1's `NotImplemented` stub.
4. **Docker isolation:**
   - In your worktree, copy `.env.example` to `.env` and set `COMPOSE_PROJECT_NAME=prompt-edit-s<N>`, plus the port offsets for your session from PLAN.md §0.
   - Run `make up`.
   - Never stop, or reuse the volumes of, another session's compose project.
5. **Branch name:** `s<N>-<slug>`, e.g. `s2-pricing`. Each green cycle is one commit, named after the behaviour.
6. **Done means:**
   - the full suite is green inside Docker: `make test` and `make lint`
   - every test in your list from PLAN.md §7 exists
   - you've reported what landed and any contract requests

---

## S1: Foundation & contracts (runs alone, merges first)

**Goal:** everything the other sessions need in order to work without talking to each other.

**Owns:** `compose.yaml`, `docker/**`, `Makefile`, `phpunit.xml`, `config/pixelforge.php`, `.env.example`, `database/migrations/**`, `app/Models/**`, `database/factories/**`, `app/Enums/**`, `app/Support/Money.php`, `app/Contracts/**`, `app/Pixelforge/**`, `app/Http/Controllers/**` (stubs only), `routes/web.php`, `routes/webhooks.php`, `resources/js/types/**`, `tests/Fixtures/**`, `tests/Fakes/**`, `tests/Pest.php`, `tests/Unit/Support/**`, `tests/Feature/Pixelforge/**`, `.github/workflows/**`, `bootstrap/app.php`.

**TDD scope:** PLAN.md Phase 0, plus the `Money` part of Phase 1.

**Deliverables**

**0. Docker stack (first, before any other test):** everything in PLAN.md §0.
- `compose.yaml` with the services `app`, `queue`, `scheduler`, `vite`, `pgsql` (with an init script that creates `testing`), `redis` and `mailpit`, plus a `tunnel` profile (cloudflared with `CLOUDFLARE_TUNNEL_TOKEN`, serving `prompt-edit.hondukash.dev`)
- the `Makefile`
- `phpunit.xml` pointed at `pgsql` / `testing`
- port and `COMPOSE_PROJECT_NAME` variables in `.env.example`
- CI running `make ci` inside the stack
- `trustProxies(at: '*')` in `bootstrap/app.php`, so the app works behind `https://prompt-edit.hondukash.dev`, plus a test that a forwarded-HTTPS request generates `https://` URLs

**1. Config and env:** `config/pixelforge.php` and `.env.example` keys: base URL, API key, webhook secret, tolerance (300 s), markup (basis points), welcome credit.

**2. Schema:** every migration, model, factory and relationship from PLAN.md §3, including the unique indexes. No business logic in models.

**3. Enums:** `GenerationStatus` (`pending`, `queued`, `processing`, `succeeded`, `failed`, plus `isFinal()`), `Size`, `Quality`, `LedgerEntryType`.

**4. `App\Support\Money`:** nanodollars, test-driven in full. Everyone uses it.

**5. `App\Pixelforge\PixelforgeClient`:** the thin HTTP layer, test-driven with `Http::fake`.
- Methods: `createGeneration(array $params, string $idempotencyKey)`, `getGeneration(string $id)`, `listGenerations(...)`, `getPricing()`, `getUsage(string $start, string $end)`.
- Returns typed DTOs: `ProviderGeneration`, `ProviderUsage`, `ProviderPricing`.
- Throws typed exceptions: `RateLimited(retryAfter)`, `ConcurrencyLimited`, `DailyLimitExceeded`, `Overloaded(retryAfter)`, `ContentPolicyViolation`, `ValidationFailed`, `NotFound`, `IdempotencyKeyReused`.
- Exposes `idempotentReplayed`.

**6. Contracts** (`app/Contracts/`), each bound to a `NotImplemented` stub:

| Interface | Methods |
|---|---|
| `CostCalculator` | `providerCost(Usage, Rates): Money` |
| `Markup` | `apply(Money $providerCost): Money` |
| `CostEstimator` | `estimate(EstimateInput): Estimate`. `Estimate` holds provider and user values for `min`, `expected` and `max`. |
| `PricingCatalog` | `current(): Rates`, `tokenModel(): TokenModel` |
| `Ledger` | `grantWelcome(User)`, `purchase(User, Money, string $token)`, `hold(Generation, Money)` (throws `InsufficientCredits`), `settle(Generation, Money $charge)`, `release(Generation)`, `available(User): Money` |
| `GenerationSync` | `apply(ProviderGeneration $g): ?Generation`. Matches on `metadata.local_id`, then `provider_id`. |

DTO value classes (`Usage`, `Rates`, `EstimateInput`, `Estimate`, `TokenModel`) live in `app/Contracts/Data/`.

**7. Routes, with stub controllers that return 501.** Each controller is owned by the session noted in the comment:

```
GET  /generations                 GenerationController@index    (S6)
GET  /generations/create          GenerationController@create   (S6)
GET  /generations/{generation}    GenerationController@show     (S6)
POST /generations                 StoreGenerationController     (S4)
POST /estimate                    EstimateController            (S4)
GET  /credits                     CreditsController@index       (S3)
POST /credits/purchase            CreditsController@purchase    (S3)
GET  /admin/report                AdminReportController         (S7)
POST /webhooks/pixelforge         PixelforgeWebhookController   (S5, CSRF-exempt)
```

**8. Inertia prop contracts:** `resources/js/types/generations.ts`, `credits.ts` and `admin.ts`. Each page's props are documented, with money as **decimal strings** (`"0.046125000"`).

**9. Test harness:**
- fixtures copied from the docs
- `PixelforgeFake`
- the `signedWebhook()` helper
- the `toEqualMoney` and `toHaveBalance` expectations
- in-memory fakes for every contract (`FakeLedger`, `FakeCostEstimator`, `FakeGenerationSync`, …)

**10. CI** workflow: Pest, Pint, Larastan, and the type-check.

**Done:** the stubs, fakes, client and Money are green, and CI passes. **The user merges S1 to `main` before S2–S7 start.**

---

## S2: Pricing engine

**Owns:** `app/Pricing/**`, `app/Providers/PricingServiceProvider.php`, `tests/Unit/Pricing/**`.

**Implements:** `CostCalculator`, `Markup`, `CostEstimator`, `PricingCatalog`. The catalog reads the client's `getPricing()`, caches it for 1 h, and falls back to config.

**TDD scope:** PLAN.md Phase 1, except Money. Must include the docs' worked example (a max of exactly $0.373875) and the bounds property test.

**Done:** all pricing contracts are bound to real implementations in `PricingServiceProvider`.

---

## S3: Ledger & credits (Phases 2 and 6)

**Owns:** `app/Billing/**`, `app/Providers/BillingServiceProvider.php`, `app/Listeners/GrantWelcomeCredit.php`, `CreditsController`, `resources/js/pages/credits/**`, `resources/js/components/credits/**`, `resources/js/components/SidebarBalance.vue`, `tests/Feature/Billing/**`, `tests/Feature/Credits/**`.

**Implements:**
- the `Ledger` contract, with an append-only `credit_transactions` table, `lockForUpdate`, and a unique `(generation_id, type)` index
- the welcome credit on `Registered`
- the credits page and fake purchase packs
- the sidebar balance component, and the shared `balance` Inertia prop through a middleware it owns: `ShareBalance`, appended in `bootstrap/app.php`. That edit to `bootstrap/app.php` must be requested from S1 via `docs/contract-requests/`, or S1 pre-registers the middleware as a no-op.

**TDD scope:** PLAN.md Phases 2 and 6.

**Done:** the invariant test (cached balance equals the ledger sum) passes after every operation.

---

## S4: Submission

**Owns:** `StoreGenerationController`, `EstimateController`, `app/Http/Requests/StoreGenerationRequest.php`, `app/Jobs/SubmitGeneration.php`, `app/Providers/SubmissionServiceProvider.php`, `tests/Feature/Submission/**`.

**Uses:** `CostEstimator`, `Ledger`, `GenerationSync` and `PixelforgeClient` through their contracts. Tests use the fakes for the first three, and `PixelforgeFake` for HTTP.

**Implements:**
- validation
- the affordability check: hold the max user price, or reject the submit
- creating the `pending` generation
- the `SubmitGeneration` job: idempotency key, `metadata.local_id`, honouring `Retry-After`, capped retries, and failing with a hold release on non-retryable errors
- a submission funnel capped at 3 concurrent / 60 per minute

**TDD scope:** PLAN.md Phase 3.

---

## S5: Webhooks & state sync

**Owns:** `PixelforgeWebhookController`, `app/Http/Middleware/VerifyPixelforgeSignature.php`, `app/Jobs/ProcessWebhookEvent.php`, `app/Generations/**`, `app/Console/Commands/ReconcileGenerations.php`, `routes/console.php`, `app/Providers/GenerationsServiceProvider.php`, `tests/Feature/Webhooks/**`, `tests/Unit/Generations/**`.

**Uses:** `Ledger`, `CostCalculator` and `Markup` through their contracts.

**Implements:**
- the signature check
- `webhook_events` dedupe with `insertOrIgnore`
- the `GenerationSync` contract: a forward-only state machine, settle on success, release on failure
- the reconcile command, scheduled every minute

**TDD scope:** PLAN.md Phase 4. Every ordering and duplicate scenario is required.

---

## S6: Generations UI

**Owns:** `GenerationController` (index, create, show), `app/Http/Resources/GenerationResource.php`, `resources/js/pages/generations/**`, `resources/js/components/generations/**`, `resources/js/composables/**`, `resources/js/lib/format.ts`, the nav entries in `resources/js/components/AppSidebar.vue`, `tests/Feature/Generations/**`, `resources/js/**/*.spec.ts`, and the Vitest config.

**Uses:**
- the prop types from S1
- `POST /estimate`, assuming the response shape from the S1 contract
- in tests, factories for generations in every status

**Implements:**
- the create form with a live, debounced estimate
- history with status badges and polling that stops when every generation is final
- the detail page with the token breakdown
- a placeholder for expired images
- the money formatter

**TDD scope:** PLAN.md Phase 5. Feature tests use Inertia assertions; components are tested with Vitest.

---

## S7: Admin report

**Owns:** `AdminReportController`, `app/Reports/**`, `app/Policies/AdminPolicy.php` (or a gate in its own provider), `resources/js/pages/admin/**`, `tests/Feature/Admin/**`.

**Uses:** `PixelforgeClient::getUsage()` from S1, and the models and factories.

**Implements:**
- per-day and per-user provider cost, credit spend and profit, grouped by the UTC day of `completed_at`
- a reconciliation column from `/v1/usage`, splitting ranges over 92 days into several calls
- an admin gate on `users.is_admin`

**TDD scope:** PLAN.md Phase 7.

---

## S8: Integration & deploy (runs after S2–S7 merge)

**Owns:** `tests/Feature/EndToEnd/**`, `database/seeders/**`, `README.md`, and deployment config.

**Does:**
1. Removes the `NotImplemented` stubs. Checks that every contract resolves to a real implementation (a container test).
2. Writes end-to-end tests that use only real classes, with HTTP faked:
   - submit → webhook `processing` → `succeeded` → one charge and the correct balance
   - submit → `failed` → $0 charged and the hold released
   - a duplicate, out-of-order storm, after which the admin report equals the fixture usage
3. Serves the app through the Cloudflare tunnel at `prompt-edit.hondukash.dev` (decided 2026-11-23 instead of Laravel Cloud): built assets, queue worker, scheduler, and the Pixelforge key with its webhook URL and secret.
4. Runs a live smoke test with `[slow]`, `[fail]` and `[duplicate]`.
5. Writes the README.

**TDD scope:** PLAN.md Phases 8 and 9.

---

## Merge order

1. **S1** to `main`.
2. **S2–S7**, in any order. Each rebases on `main` before merging. The only expected conflict is `bootstrap/providers.php`.
3. **S8**, from `main` once all six are in.
