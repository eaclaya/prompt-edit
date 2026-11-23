# Contracts (landed by S1)

S2–S7 code **only** against what is listed here. Anything missing or wrong: write it in
`docs/contract-requests/<session>.md` and stop to ask (SESSIONS.md, ground rule 1).

Money is always `App\Support\Money` in PHP and a nine-decimal string (`"0.046125000"`) on
the wire. Never a float.

---

## 0. Working in your worktree

```bash
cp .env.example .env          # then set COMPOSE_PROJECT_NAME and the ports below
make build up install         # first time: build the image, start, composer + npm install
docker compose exec -u sail app php artisan key:generate
make test                     # Pest on Postgres (`testing` DB) — ARGS="--filter=Foo" to narrow
make lint                     # Pint --test, Larastan (level 7), vp check resources, vue-tsc
```

| Session | `COMPOSE_PROJECT_NAME` | `APP_PORT` | `FORWARD_DB_PORT` | `FORWARD_REDIS_PORT` | `VITE_PORT` | `FORWARD_MAILPIT_DASHBOARD_PORT` |
| ------- | ---------------------- | ---------- | ----------------- | -------------------- | ----------- | -------------------------------- |
| S<N>    | `prompt-edit-s<N>`     | `800<N>`   | `540<N>`          | `630<N>`             | `520<N>`    | `802<N>`                         |

Also set `WWWUSER` / `WWWGROUP` to your `id -u` / `id -g`. Copy `PIXELFORGE_API_KEY` /
`PIXELFORGE_WEBHOOK_SECRET` from the main checkout's `.env` if you need live calls (tests never do:
`phpunit.xml` forces `pf_test_key`, `whsec_test_secret`, base URL `https://pixelforge.test/v1`).

- `make` targets run inside `app` as the `sail` user; inside a container (`LARAVEL_SAIL=1`) they run directly.
- Services: `app` (artisan serve :80), `queue` (`queue:work redis --tries=1`), `scheduler` (`schedule:work`),
  `vite` (HMR on `localhost:${VITE_PORT}`), `pgsql` (postgres:17, DBs `laravel` + `testing`), `redis`, `mailpit`.
- `make tunnel` stops vite, builds assets and runs cloudflared (`CLOUDFLARE_TUNNEL_TOKEN`) for
  `https://prompt-edit.hondukash.dev` → `http://app:80`. One stack at a time (normally S5/S8).
- `make fresh` = `migrate:fresh --seed`. `make shell` = bash in `app`. `make ci` = what CI runs.
- CI (`.github/workflows/tests.yml`): builds the image, `docker compose up --wait pgsql redis`,
  then `docker compose run --rm --no-deps app make ci` (composer/npm install, build, lint, test).

**Test layout.** `tests/Feature/**` get `Tests\TestCase` + `RefreshDatabase` (Postgres, in a
transaction). `tests/Unit/**` get `Tests\TestCase` without the database. Write Pest tests.

---

## 1. Config — `config/pixelforge.php`

| Key | Env | Default |
| --- | --- | --- |
| `base_url` | `PIXELFORGE_BASE_URL` | `https://pixelforge.promptedit.dev/v1` |
| `api_key` | `PIXELFORGE_API_KEY` | – |
| `webhook_secret` | `PIXELFORGE_WEBHOOK_SECRET` | – (`whsec_…`) |
| `webhook_tolerance` | `PIXELFORGE_WEBHOOK_TOLERANCE` | `300` (int seconds) |
| `timeout`, `connect_timeout` | `PIXELFORGE_TIMEOUT`, `PIXELFORGE_CONNECT_TIMEOUT` | `10`, `5` |
| `limits.requests_per_minute`, `limits.max_concurrent` | `PIXELFORGE_REQUESTS_PER_MINUTE`, `PIXELFORGE_MAX_CONCURRENT` | `60`, `3` |
| `markup_bps` | `MARKUP_BPS` | `2500` (int, = 25%) |
| `welcome_credit` | `WELCOME_CREDIT_USD` | `'1.00'` (string → `Money::of()`) |
| `pricing.cache_ttl` | – | `3600` |
| `pricing.rates` | – | docs rates, decimal strings keyed by token type |
| `pricing.tokens` | – | docs token model (same shape as `/v1/pricing`'s `tokens`) |

`config('pixelforge.pricing')` has the same `rates`/`tokens` shape as the `/v1/pricing` body, so
`ProviderPricing::fromArray(config('pixelforge.pricing'))` works as the fallback.

---

## 2. `App\Support\Money` (nanodollars, immutable)

```php
Money::of('0.046125000')   // string only; floats, >9 decimals, '1e3', '.5', '$1' throw InvalidArgumentException
Money::ofNanos(46_125_000); Money::zero(); Money::sum([...]); Money::max($a, $b, ...); Money::min(...)
$m->nanos                  // int (public readonly)
$m->add($o); $m->subtract($o); $m->negate()                  // overflow throws OverflowException
$m->multiplyRatio(125, 100)   // exact via brick/math, rounded UP (towards +∞) to a whole nanodollar
$m->compareTo($o) /* -1|0|1 */; equals, isGreaterThan(OrEqualTo), isLessThan(OrEqualTo), isZero, isNegative, isPositive
$m->toDecimal()   // "0.046125000" (also __toString and jsonSerialize)
$m->format()      // UI only: "$0.0461"; "$0.000575" when non-zero and below 1 cent; "-$0.2500"; half-up
```

Eloquent cast: `'col_nanos' => Money::class` reads a `bigint` as `Money` (null stays null) and
accepts `Money`, an `int` of nanos, or null on write. JSON/array serialisation gives the decimal string.

---

## 3. Enums — `app/Enums`

| Enum | Cases (value) | Extras |
| --- | --- | --- |
| `GenerationStatus` | `Pending` `pending`, `Queued` `queued`, `Processing` `processing`, `Succeeded` `succeeded`, `Failed` `failed` | `isFinal()` (succeeded/failed), `label()` (`pending` → "Queued") |
| `Size` | `Square` `1024x1024`, `Landscape` `1536x1024`, `Portrait` `1024x1536` | `Size::default()` = Square |
| `Quality` | `Low` `low`, `Medium` `medium`, `High` `high` | `Quality::default()` = Medium |
| `LedgerEntryType` | `Purchase` `purchase`, `Welcome` `welcome`, `Hold` `hold`, `HoldRelease` `hold_release`, `Charge` `charge` | |
| `TokenType` | `InputText` `input_text_tokens`, `InputImage` `input_image_tokens`, `Output` `output_tokens`, `Reasoning` `reasoning_tokens` | API keys of `usage`/`rates` |

The forward-only transition rule is **not** on the enum; it belongs to S5's `GenerationSync`.

---

## 4. Schema, models, factories

No business logic in models. All timestamps are `timestampTz`. JSON columns are **`jsonb`: key
order is not preserved** — compare decoded arrays with `toEqual`, not `toBe`.

**`users`** (+ `App\Models\User`): `is_admin` bool (default false), `balance_nanos` bigint (default 0,
cast `Money`; only the Ledger writes it; not fillable — use `forceFill`). Relations:
`generations()`, `creditTransactions()`. Factory: `->admin()`, `->withBalance('1.00')` (sets the
cached column directly; ledger tests should go through the Ledger), `->unverified()`.
`auth.user` (shared by `HandleInertiaRequests`) therefore also carries `is_admin` and `balance_nanos`.

**`generations`** (`App\Models\Generation`, ULID `id`, `$guarded = []`):

| Column | Type / cast | Notes |
| --- | --- | --- |
| `id` | ULID | also `metadata.local_id` sent to Pixelforge |
| `user_id` | FK nullable, **nullOnDelete** | financial history outlives the account |
| `provider_id` | string nullable **unique** | `gen_…` |
| `idempotency_key` | string **unique** | defaults to `id` on create |
| `prompt` | text | |
| `size`, `quality` | `Size`, `Quality` | |
| `reference_images` | jsonb array, default `[]` | |
| `status` | `GenerationStatus`, default `pending` | indexed with `updated_at` (poller) |
| `error_code`, `error_message` | nullable | |
| `rates` | jsonb array nullable | as received: `['output_tokens' => '40.00', …]` |
| `est_min_nanos`, `est_expected_nanos`, `est_max_nanos` | bigint → `Money` | **user** prices (after markup); max = amount held |
| `usage` | jsonb array nullable | token counts incl. `total_tokens` |
| `provider_cost_nanos`, `charged_nanos` | bigint nullable → `Money` | charged = what the user paid; `0` for failed |
| `image_url` (text), `image_url_expires_at` | nullable | |
| `submitted_at`, `started_at`, `completed_at` | nullable datetimes | report groups by UTC day of `completed_at` (indexed) |

Relations: `user()`, `creditTransactions()`. Factory states: `pending()` (default), `queued()`
(provider id, rates, submitted_at), `processing()`, `succeeded()` (the docs' example: high,
1536x1024, 1 reference, usage 12+765+6450+3184, provider cost `0.305510`, charged `0.3818875`,
live image), `failed($code = 'generation_failed', $message)` (charged 0), `imageExpired()`.

**`credit_transactions`** (`App\Models\CreditTransaction`): `user_id` (FK nullable, nullOnDelete),
`generation_id` (ULID FK nullable, nullOnDelete), `type` (`LedgerEntryType`), `amount_nanos`
(signed → `Money`), `balance_after_nanos` (→ `Money`), `idempotency_key` (nullable **unique**: the
purchase token), `meta` (jsonb array nullable), timestamps. **Partial unique index**
`(generation_id, type) WHERE generation_id IS NOT NULL`. Factory: a $10 purchase row (raw; balances
not recomputed). How holds affect `balance_nanos` vs. "available" is S3's design within these columns.

**`webhook_events`** (`App\Models\WebhookEvent`, no `created_at/updated_at`): `event_id` (**unique**,
`evt_…`), `type`, `generation_provider_id` (nullable, indexed), `payload` (jsonb array),
`received_at`, `processed_at` (nullable). Dedupe with
`DB::table('webhook_events')->insertOrIgnore([...])` (returns 1 for new, 0 for duplicate;
`payload` must be JSON-encoded for the query builder). Factory: a `generation.processing` event.

---

## 5. Pixelforge client — `App\Pixelforge`

Resolve with `app(PixelforgeClient::class)` (bound in `ContractsServiceProvider` from config).
No retries and no business rules inside.

```php
createGeneration(array $params, string $idempotencyKey): ProviderGeneration   // POST /generations (send only allowed fields)
getGeneration(string $id): ProviderGeneration                                 // GET /generations/{id}
listGenerations(int $limit = 20, ?GenerationStatus $status = null, ?string $cursor = null): ProviderGenerationPage
getPricing(): ProviderPricing                                                 // GET /pricing (no auth header)
getUsage(string $start, string $end): ProviderUsage                           // GET /usage?start_date&end_date (YYYY-MM-DD, ≤ 92 days)
```

**DTOs** (`App\Pixelforge\Data`, readonly):

- `ProviderGeneration`: `id`, `status` (`GenerationStatus`, never `Pending`), `prompt`, `size`, `quality`,
  `referenceImages`, `metadata` (string map), `imageUrl`, `imageUrlExpiresAt`, `usage` (`?Usage`),
  `rates` (`Rates`), `errorCode`, `errorMessage`, `createdAt`, `startedAt`, `completedAt`
  (`CarbonImmutable` UTC), `raw` (the object as received), **`idempotentReplayed`** (true when the
  202 carried `Idempotent-Replayed: true`), `localId()` = `metadata['local_id']`.
  `ProviderGeneration::fromArray($webhook['data']['object'])` parses webhook objects too.
- `ProviderGenerationPage`: `data` (list of `ProviderGeneration`), `hasMore`, `nextCursor`.
- `ProviderPricing`: `rates` (`Rates`), `tokens` (`TokenModel`), `raw` (cache this).
- `ProviderUsage`: `startDate`, `endDate`, `days` (list of `ProviderUsageDay`), `total`, `forDate('Y-m-d')`.
  `ProviderUsageDay`: `date` (null on the total), `generations`, `usage` (`Usage`), `cost` (`Money`, from `cost_usd`).

**Exceptions** (`App\Pixelforge\Exceptions`, all extend `PixelforgeException` with `errorCode`,
`errorType`, `status`, `retryAfter` (`?int` seconds from `Retry-After`), `param`, `errors`, `isRetryable()`):

| Class | API code | HTTP | Retryable |
| --- | --- | --- | --- |
| `InvalidApiKey` | `invalid_api_key` | 401 | no |
| `ValidationFailed` (`param`, `errors`) | `validation_failed` | 422 | no |
| `ContentPolicyViolation` | `content_policy_violation` | 400 | no |
| `NotFound` | `not_found` | 404 | no |
| `IdempotencyKeyReused` | `idempotency_key_reused` | 409 | no |
| `RateLimited` | `rate_limit_exceeded` | 429 | yes, `retryAfter` |
| `ConcurrencyLimited` | `concurrency_limit_exceeded` | 429 | yes, `retryAfter` (5) |
| `DailyLimitExceeded` | `daily_limit_exceeded` | 429 | no |
| `Overloaded` | `overloaded` | 503 | yes, `retryAfter` (2) |
| `DateRangeTooLong` | `date_range_too_long` | 422 | no |
| `ServerError` | other 5xx (`server_error`) | 5xx | yes |
| `ConnectionFailed` | `connection_failed` (timeout, DNS…) | – | yes |
| `PixelforgeException` itself | any other 4xx code | 4xx | no |

---

## 6. Contracts — `app/Contracts`

Until a session binds one, each resolves to `App\Contracts\NotImplemented\NotImplemented*`, which
throws `NotImplementedException` ("… is not implemented yet (S<N>)"). Stubs are bound with
`bindIf()` in `App\Providers\ContractsServiceProvider`, so **your provider's plain `bind()` /
`singleton()` wins** regardless of order. Register yours by appending one line to
`bootstrap/providers.php`.

| Interface | Methods | Owner |
| --- | --- | --- |
| `CostCalculator` | `providerCost(Usage $usage, Rates $rates): Money` | S2 |
| `Markup` | `apply(Money $providerCost): Money` (× (10000 + markup_bps)/10000, ceil; 0 → 0) | S2 |
| `CostEstimator` | `estimate(EstimateInput $input): Estimate` | S2 |
| `PricingCatalog` | `current(): Rates`, `tokenModel(): TokenModel` | S2 |
| `Ledger` | `grantWelcome(User)`, `purchase(User, Money, string $token)`, `hold(Generation, Money)` **throws `InsufficientCredits`**, `settle(Generation, Money $charge)`, `release(Generation)`, `available(User): Money` — all `void` except `available`; generation ops idempotent | S3 |
| `GenerationSync` | `apply(ProviderGeneration $g): ?Generation` — match `metadata.local_id`, then `provider_id`; forward-only; settle/release; idempotent; null when unknown | S5 |

**Data** (`App\Contracts\Data`, readonly):

- `Usage(inputTextTokens, inputImageTokens, outputTokens, reasoningTokens)`, `fromArray()` (API keys;
  throws on missing/negative), `tokens(TokenType)`, `total()`, `toArray()` (adds `total_tokens`).
- `Rates(inputText, inputImage, output, reasoning)` — `Money` per 1M tokens; `fromArray()` (decimal
  strings; throws if missing), `rate(TokenType)`, `toArray()` (9-decimal strings), `Rates::TOKENS_PER_RATE` = 1_000_000.
- `TokenModel(charactersPerTextToken, textTokenVarianceBps, perReferenceImage, output, reasoning)`,
  `fromArray()` (the `tokens` block; `0.15` → `1500` bps), `outputTokens(Quality, Size)`,
  `minReasoningTokens(Quality)`, `maxReasoningTokens(Quality)`.
- `EstimateInput(prompt, Size, Quality, referenceImageCount = 0)`, `promptCharacters()` (Unicode chars).
- `Estimate(providerMin, providerExpected, providerMax, userMin, userExpected, userMax)`;
  `toArray()` = `['min','expected','max']` **user** prices as decimal strings (never provider cost).

**`App\Contracts\Exceptions\InsufficientCredits`**: `required`, `available` (`Money`), `shortfall()`;
message `Insufficient credits: $0.5000 required, $0.2000 available.`

---

## 7. Routes

All app routes are inside `['auth', 'verified']` (`routes/web.php`) and currently `abort(501)` from
their stub controller. The owner replaces the controller body; the routes file stays S1's.

| Method | URI | Name | Controller | Owner | Responds with |
| --- | --- | --- | --- | --- | --- |
| GET | `/generations` | `generations.index` | `GenerationController@index` | S6 | Inertia `generations/Index` |
| GET | `/generations/create` | `generations.create` | `GenerationController@create` | S6 | Inertia `generations/Create` |
| GET | `/generations/{generation}` | `generations.show` | `GenerationController@show` (route-model bound by ULID; 404 if missing) | S6 | Inertia `generations/Show`; 404 for another user's |
| POST | `/generations` | `generations.store` | `StoreGenerationController` | S4 | redirect to `generations.index`; validation errors via session |
| POST | `/estimate` | `estimate` | `EstimateController` | S4 | JSON `EstimateResponse`; 422 JSON errors |
| GET | `/credits` | `credits.index` | `CreditsController@index` | S3 | Inertia `credits/Index` |
| POST | `/credits/purchase` | `credits.purchase` | `CreditsController@purchase` | S3 | redirect back; 422 for unknown pack |
| GET | `/admin/report` | `admin.report` | `AdminReportController` | S7 | Inertia `admin/Report`; **S7 authorises** (403 for non-admins) inside its controller or a gate in its own provider |
| POST | `/webhooks/pixelforge` | `webhooks.pixelforge` | `PixelforgeWebhookController` | S5 | 2xx fast; 400 bad signature |

`routes/webhooks.php` is loaded **outside the web group** (no session, cookies, CSRF or auth).
S5 attaches its `VerifyPixelforgeSignature` middleware from the controller
(`implements HasMiddleware` → `public static function middleware(): array`), not the routes file.

**Middleware slot:** `App\Http\Middleware\ShareBalance` is appended to the `web` group right after
`HandleInertiaRequests` and is a no-op. S3 owns it from here: make it
`Inertia::share('balance', fn () => ...)` with the `SharedBalance` shape. No `bootstrap/app.php` edit needed.

`bootstrap/app.php` also has `trustProxies(at: '*')` (https URLs behind the tunnel / Laravel Cloud).

---

## 8. Inertia props and JSON shapes — `resources/js/types`

Import from `@/types`. `Money` = decimal string; `Paginated<T>` = Laravel's paginated
`JsonResource::collection($paginator)` (`data`, `links`, `meta`).

| Page / endpoint | Type | File |
| --- | --- | --- |
| `generations/Index` | `GenerationsIndexProps { generations: Paginated<GenerationSummary> }` | `generations.ts` |
| `generations/Create` | `GenerationsCreateProps { sizes, qualities: Option[], defaults, limits {prompt_max_length: 4000, reference_images_max: 4}, available }` | `generations.ts` |
| `generations/Show` | `GenerationsShowProps { generation: GenerationDetail }` (adds `usage`, `rates`, `breakdown: CostBreakdown`, `image_url_expires_at`, `started_at`) | `generations.ts` |
| `POST /generations` body | `StoreGenerationRequest { prompt, size, quality, reference_images }` | `generations.ts` |
| `POST /estimate` | body `EstimateRequest` (= store body); 200 `EstimateResponse { estimate: {min, expected, max}, available, can_afford }` | `generations.ts` |
| `credits/Index` | `CreditsIndexProps { balance, available, packs: CreditPack[], transactions: Paginated<CreditTransactionRow> }` | `credits.ts` |
| `POST /credits/purchase` body | `PurchaseRequest { pack, token }` | `credits.ts` |
| shared prop `balance` | `SharedBalance { balance, available } \| null` (typed in `global.d.ts`, optional until S3) | `credits.ts` |
| `admin/Report` | `AdminReportProps { filters {start, end}, days: ReportDay[], users: ReportUser[], totals, usage_error }` | `admin.ts` |

`GenerationSummary` = `id, prompt, size, quality, reference_images, status, status_label, is_final,
estimate {min, expected, max}, charged | null, image_url | null, image_expired, error {code, message} | null,
created_at, completed_at`. Full field docs are in the `.ts` files.

---

## 9. Test harness

**Fixtures** — `tests/Fixtures/pixelforge/*.json` (the docs' payloads; see its README).
`Tests\Fixtures\PixelforgeFixtures`:

```php
PixelforgeFixtures::load('generation.succeeded', ['metadata' => ['local_id' => $g->id]]); // recursive merge, lists replaced
PixelforgeFixtures::event('generation.succeeded', $generationOverrides, ?$eventId);         // envelope with a fresh evt_ id
PixelforgeFixtures::names();
```

Names: `generation.{queued,processing,succeeded,failed}`, `generations.list`, `pricing`, `usage`,
`event.generation.{processing,succeeded,failed}`, `event.ping` (`data.object` is `null` — the docs
don't show a ping body, so handle it defensively), `errors/<code>` for every documented code.

**`Tests\Fakes\PixelforgeFake`** — wraps `Http::fake()`. Per-endpoint queues
(`create`, `retrieve`, `list`, `pricing`, `usage`): played in order, the last one repeats; a request
with nothing queued throws `LogicException`.

```php
$pixelforge = PixelforgeFake::fake()
    ->failsWith('overloaded', retryAfter: 2)       // endpoint: 'create' by default; Retry-After defaults from the docs
    ->acceptsGeneration();                         // 202, echoes prompt/size/quality/reference_images/metadata
$pixelforge->acceptsGeneration(['status' => 'processing'], replayed: true);
$pixelforge->returnsGeneration('generation.succeeded', $overrides);   // id taken from the URL
$pixelforge->listsGenerations([['id' => 'gen_1', 'status' => 'processing']], nextCursor: 'c');
$pixelforge->returnsPricing(['rates' => ['output_tokens' => '50.00']]);
$pixelforge->returnsUsage(['2026-11-01' => ['generations' => 2, 'output_tokens' => 2200, 'cost_usd' => '0.088000000']]); // zeros elsewhere, total computed
$pixelforge->failsToConnect('retrieve');
$pixelforge->assertCreated(fn (array $body, array $headers) => $headers['Idempotency-Key'] === $g->id);
$pixelforge->assertCreatedTimes(1); $pixelforge->assertNothingCreated(); $pixelforge->assertUsageRequested('2026-11-01', '2026-11-30');
$pixelforge->created();                            // list<Illuminate\Http\Client\Request>
```

**Webhooks** (global helpers in `tests/Pest.php`):

```php
$webhook = signedWebhook($payload, timestamp: null, secret: null, attempt: 1); // Tests\Fakes\SignedWebhook
$webhook->body; $webhook->headers['Pixelforge-Signature']; $webhook->timestamp; $webhook->server();
$webhook->withSignature('t=1,v1=bad'); $webhook->withSignature(null);
postWebhook($payload)->assertOk();                 // raw-body POST /webhooks/pixelforge, also accepts a SignedWebhook
postWebhook($payload, timestamp: now()->subMinutes(10)->getTimestamp());
```

Signature: `t=<unix>,v1=hex(HMAC-SHA256("{t}.{raw body}", config('pixelforge.webhook_secret')))`;
headers also include `Pixelforge-Event-Id`, `Pixelforge-Event-Type`, `Pixelforge-Delivery-Attempt`,
`User-Agent: Pixelforge-Webhooks/1.0`.

**Expectations**: `expect($money)->toEqualMoney('0.373875')` (value and expected may each be `Money`,
an int of nanos, or a decimal string); `expect($user)->toHaveBalance('1.00')` (re-reads
`users.balance_nanos`).

**Contract fakes** (`tests/Fakes`) — `Fake*::swap()` binds a new instance into the container and returns it.

| Fake | Behaviour | Knobs / assertions |
| --- | --- | --- |
| `FakeCostCalculator` | Σ rate × tokens / 1M, each line rounded up | `returns(Money)`, `$calls` |
| `FakeMarkup` | × (10000 + `markup_bps`) / 10000, ceil | `returns(Money)` |
| `FakeCostEstimator` | the docs' worked example (provider max `0.373875`, user `0.35778125 / 0.4125625 / 0.46734375`) | `returns(Estimate)`, `returnsUserPrices('0.10','0.20','0.30')`, `$inputs` |
| `FakePricingCatalog` | `config('pixelforge.pricing')` | `withRates([...])` |
| `FakeLedger` | in memory, starts from `users.balance_nanos`, **never writes the DB**; same idempotency (welcome once, token once, one hold/settle/release per generation); throws `InsufficientCredits` | `balance(User)`, `setBalance(User, Money)`, `assertHeld($g, '0.40')`, `assertNothingHeld()`, `assertSettled($g, '0.30')`, `assertNotSettled`, `assertReleased`, `assertNotReleased`, `$held`, `$settled`, `$released` |
| `FakeGenerationSync` | records, returns the matching row, changes nothing | `writeThrough()` (naive forward-only copy of provider fields, no ledger), `assertApplied(fn)`, `assertAppliedTimes(n)`, `assertNothingApplied()`, `$applied` |

---

## 10. Things to know

- Feature tests that render Inertia pages need built assets (`npm run build`, done by `make install`/CI)
  or `$this->withoutVite()`.
- Nothing grants the welcome credit yet: registering a user doesn't touch the Ledger until S3 adds its
  `Registered` listener.
- `Generation::$idempotency_key` defaults to the ULID; S4 sends `metadata.local_id = $generation->id`.
- `est_*_nanos` are **user** prices; the provider side of an estimate is not stored.
- Pixelforge `rates` arrive as `"5.00"`; store `$providerGeneration->raw['rates']` (as received) or
  `Rates::toArray()` (9 decimals). Both parse back with `Rates::fromArray()`.
- Generation `user_id` and ledger `user_id` are nullable (account deletion keeps the money trail).
