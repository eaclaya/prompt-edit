<?php

namespace Tests\Fakes;

use App\Support\Money;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Assert;
use Tests\Fixtures\PixelforgeFixtures;

/**
 * Fakes the Pixelforge HTTP API with Http::fake(), using the docs fixtures.
 *
 * Each endpoint keeps a queue of responses: they are played in order and the
 * last one repeats. Endpoints: create (POST /generations), retrieve
 * (GET /generations/{id}), list (GET /generations), pricing, usage.
 *
 *     $pixelforge = PixelforgeFake::fake()
 *         ->failsWith('overloaded', retryAfter: 2)
 *         ->acceptsGeneration();
 */
final class PixelforgeFake
{
    /** HTTP status of each documented error code. */
    public const array STATUS = [
        'invalid_api_key' => 401,
        'validation_failed' => 422,
        'content_policy_violation' => 400,
        'not_found' => 404,
        'idempotency_key_reused' => 409,
        'rate_limit_exceeded' => 429,
        'concurrency_limit_exceeded' => 429,
        'daily_limit_exceeded' => 429,
        'overloaded' => 503,
        'date_range_too_long' => 422,
    ];

    /** Retry-After the docs give for some codes, used when none is passed. */
    private const array DEFAULT_RETRY_AFTER = [
        'rate_limit_exceeded' => 60,
        'concurrency_limit_exceeded' => 5,
        'overloaded' => 2,
    ];

    /** @var array<string, list<Closure(Request): (PromiseInterface)>> */
    private array $queues = [];

    public static function fake(): self
    {
        $fake = new self;

        Http::fake(fn (Request $request) => $fake->respond($request));

        return $fake;
    }

    /**
     * 202 for POST /generations: the generation.queued fixture, echoing the request's
     * prompt, size, quality, reference_images and metadata, then $overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    public function acceptsGeneration(array $overrides = [], bool $replayed = false): self
    {
        return $this->push('create', function (Request $request) use ($overrides, $replayed) {
            /** @var array<string, mixed> $body */
            $body = $request->data();
            $echo = array_intersect_key($body, array_flip(['prompt', 'size', 'quality', 'reference_images', 'metadata']));

            $generation = PixelforgeFixtures::load('generation.queued', [
                'id' => self::generationId(),
                'metadata' => [],
                'reference_images' => [],
                'created_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
            ]);
            $generation = array_replace($generation, $echo);
            $generation = PixelforgeFixtures::load('generation.queued', array_replace($generation, $overrides));

            return Http::response($generation, 202, $replayed ? ['Idempotent-Replayed' => 'true'] : []);
        });
    }

    /**
     * 200 for GET /generations/{id}: a generation fixture with the requested id, then $overrides.
     *
     * @param  string  $fixture  generation.queued | generation.processing | generation.succeeded | generation.failed
     * @param  array<string, mixed>  $overrides
     */
    public function returnsGeneration(string $fixture = 'generation.processing', array $overrides = []): self
    {
        return $this->push('retrieve', function (Request $request) use ($fixture, $overrides) {
            $id = rawurldecode(Str::afterLast(parse_url($request->url(), PHP_URL_PATH) ?: '', '/'));

            return Http::response(PixelforgeFixtures::load($fixture, ['id' => $id, ...$overrides]));
        });
    }

    /**
     * 200 for GET /generations. Each item overrides the generation.queued fixture.
     *
     * @param  list<array<string, mixed>>  $generations
     */
    public function listsGenerations(array $generations, ?string $nextCursor = null): self
    {
        $data = array_map(fn (array $overrides) => PixelforgeFixtures::load('generation.queued', $overrides), $generations);

        return $this->push('list', fn () => Http::response([
            'object' => 'list',
            'data' => $data,
            'has_more' => $nextCursor !== null,
            'next_cursor' => $nextCursor,
        ]));
    }

    /** @param  array<string, mixed>  $overrides */
    public function returnsPricing(array $overrides = []): self
    {
        return $this->push('pricing', fn () => Http::response(PixelforgeFixtures::load('pricing', $overrides)));
    }

    /**
     * 200 for GET /usage over the requested range. Days not given are zeros;
     * the total is computed from the days.
     *
     * @param  array<string, array<string, int|string>>  $days  'YYYY-MM-DD' => generations, *_tokens, cost_usd
     */
    public function returnsUsage(array $days = []): self
    {
        return $this->push('usage', function (Request $request) use ($days) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $start = CarbonImmutable::parse((string) $query['start_date']);
            $end = CarbonImmutable::parse((string) $query['end_date']);

            $data = [];
            for ($day = $start; $day->lte($end); $day = $day->addDay()) {
                $data[] = ['date' => $day->toDateString(), ...self::usageRow($days[$day->toDateString()] ?? [])];
            }

            $total = self::usageRow([]);
            $totalNanos = 0;
            foreach ($data as $row) {
                foreach (['generations', 'input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'] as $key) {
                    $total[$key] += $row[$key];
                }
                $totalNanos += Money::of((string) $row['cost_usd'])->nanos;
            }
            $total['cost_usd'] = Money::ofNanos($totalNanos)->toDecimal();

            return Http::response([
                'object' => 'usage',
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
                'data' => $data,
                'total' => $total,
            ]);
        });
    }

    /** A documented error (fixture body, status and Retry-After) on an endpoint. */
    public function failsWith(string $code, ?int $retryAfter = null, string $endpoint = 'create'): self
    {
        $status = self::STATUS[$code] ?? throw new LogicException("Unknown Pixelforge error code [{$code}].");
        $retryAfter ??= self::DEFAULT_RETRY_AFTER[$code] ?? null;

        return $this->push($endpoint, fn () => Http::response(
            PixelforgeFixtures::load("errors/{$code}"),
            $status,
            $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter],
        ));
    }

    public function failsToConnect(string $endpoint = 'create'): self
    {
        return $this->push($endpoint, fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
    }

    /**
     * Assert a create request matched: fn (array $body, array<string, string> $headers): bool.
     */
    public function assertCreated(?Closure $callback = null): void
    {
        Assert::assertNotEmpty(
            array_filter($this->created(), fn (Request $r) => $callback === null || $callback($r->data(), self::headers($r))),
            'No matching POST /v1/generations was sent.',
        );
    }

    public function assertCreatedTimes(int $times): void
    {
        Assert::assertCount($times, $this->created(), "Expected {$times} POST /v1/generations.");
    }

    public function assertNothingCreated(): void
    {
        $this->assertCreatedTimes(0);
    }

    public function assertUsageRequested(string $start, string $end): void
    {
        Http::assertSent(fn (Request $request) => self::endpoint($request) === 'usage'
            && str_contains($request->url(), "start_date={$start}&end_date={$end}"));
    }

    /** @return list<Request> every request sent to POST /v1/generations */
    public function created(): array
    {
        return array_values(array_map(
            fn (array $pair) => $pair[0],
            Http::recorded(fn (Request $request) => self::endpoint($request) === 'create')->all(),
        ));
    }

    /** @internal called by Http::fake */
    public function respond(Request $request): PromiseInterface
    {
        $endpoint = self::endpoint($request);
        $queue = $this->queues[$endpoint] ?? [];

        if ($queue === []) {
            throw new LogicException("PixelforgeFake has no response for [{$endpoint}] ({$request->method()} {$request->url()}).");
        }

        $responder = count($queue) > 1 ? array_shift($this->queues[$endpoint]) : $queue[0];

        return $responder($request);
    }

    private function push(string $endpoint, Closure $responder): self
    {
        $this->queues[$endpoint][] = $responder;

        return $this;
    }

    private static function endpoint(Request $request): string
    {
        $path = trim(Str::after((string) parse_url($request->url(), PHP_URL_PATH), '/v1'), '/');

        return match (true) {
            $path === 'generations' && $request->method() === 'POST' => 'create',
            $path === 'generations' => 'list',
            str_starts_with($path, 'generations/') => 'retrieve',
            default => $path,
        };
    }

    /** @return array<string, string> */
    private static function headers(Request $request): array
    {
        return array_map(fn (array $values) => (string) ($values[0] ?? ''), $request->headers());
    }

    /**
     * @param  array<string, int|string>  $row
     * @return array{generations: int, input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, cost_usd: string}
     */
    private static function usageRow(array $row): array
    {
        return [
            'generations' => (int) ($row['generations'] ?? 0),
            'input_text_tokens' => (int) ($row['input_text_tokens'] ?? 0),
            'input_image_tokens' => (int) ($row['input_image_tokens'] ?? 0),
            'output_tokens' => (int) ($row['output_tokens'] ?? 0),
            'reasoning_tokens' => (int) ($row['reasoning_tokens'] ?? 0),
            'cost_usd' => (string) ($row['cost_usd'] ?? '0.000000000'),
        ];
    }

    private static function generationId(): string
    {
        return 'gen_'.Str::upper((string) Str::ulid());
    }
}
