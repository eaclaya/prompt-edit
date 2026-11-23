<?php

use App\Contracts\Data\Rates;
use App\Contracts\Data\Usage;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Pixelforge\Data\ProviderGeneration;
use App\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Pixelforge\Exceptions\ConnectionFailed;
use App\Pixelforge\Exceptions\ContentPolicyViolation;
use App\Pixelforge\Exceptions\DailyLimitExceeded;
use App\Pixelforge\Exceptions\DateRangeTooLong;
use App\Pixelforge\Exceptions\IdempotencyKeyReused;
use App\Pixelforge\Exceptions\InvalidApiKey;
use App\Pixelforge\Exceptions\NotFound;
use App\Pixelforge\Exceptions\Overloaded;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\Exceptions\RateLimited;
use App\Pixelforge\Exceptions\ServerError;
use App\Pixelforge\Exceptions\ValidationFailed;
use App\Pixelforge\PixelforgeClient;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\PixelforgeFixtures;

function client(): PixelforgeClient
{
    return app(PixelforgeClient::class);
}

it('creates a generation with the bearer token and idempotency key', function () {
    Http::fake([
        'pixelforge.test/v1/generations' => Http::response(PixelforgeFixtures::load('generation.queued'), 202),
    ]);

    $params = [
        'prompt' => 'A lighthouse on a cliff at dawn, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/style.png'],
        'metadata' => ['local_id' => '01KAB'],
    ];

    $generation = client()->createGeneration($params, '01KAB');

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request->hasHeader('Idempotency-Key', '01KAB')
        && $request->data() === $params);

    expect($generation)->toBeInstanceOf(ProviderGeneration::class)
        ->and($generation->id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->size)->toBe(Size::Landscape)
        ->and($generation->quality)->toBe(Quality::High)
        ->and($generation->prompt)->toBe('A lighthouse on a cliff at dawn, oil painting')
        ->and($generation->referenceImages)->toBe(['https://example.com/style.png'])
        ->and($generation->metadata)->toBe(['order_id' => '1042'])
        ->and($generation->usage)->toBeNull()
        ->and($generation->imageUrl)->toBeNull()
        ->and($generation->errorCode)->toBeNull()
        ->and($generation->createdAt->toIso8601ZuluString())->toBe('2026-11-04T14:03:11Z')
        ->and($generation->startedAt)->toBeNull()
        ->and($generation->idempotentReplayed)->toBeFalse()
        ->and($generation->rates)->toBeInstanceOf(Rates::class)
        ->and($generation->rates->output->toDecimal())->toBe('40.000000000')
        ->and($generation->raw)->toBe(PixelforgeFixtures::load('generation.queued'));
});

it('exposes the local id sent in metadata', function () {
    $generation = ProviderGeneration::fromArray(PixelforgeFixtures::load('generation.queued', ['metadata' => ['local_id' => '01KAB']]));

    expect($generation->localId())->toBe('01KAB')
        ->and(ProviderGeneration::fromArray(PixelforgeFixtures::load('generation.queued'))->localId())->toBeNull();
});

it('says when Pixelforge replayed an idempotent request', function () {
    Http::fake([
        'pixelforge.test/v1/generations' => Http::response(PixelforgeFixtures::load('generation.processing'), 202, ['Idempotent-Replayed' => 'true']),
    ]);

    $generation = client()->createGeneration(['prompt' => 'x'], 'key');

    expect($generation->idempotentReplayed)->toBeTrue()
        ->and($generation->status)->toBe(GenerationStatus::Processing);
});

it('retrieves a succeeded generation with its usage and image', function () {
    Http::fake([
        'pixelforge.test/v1/generations/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K' => Http::response(PixelforgeFixtures::load('generation.succeeded')),
    ]);

    $generation = client()->getGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->usage)->toEqual(new Usage(inputTextTokens: 12, inputImageTokens: 765, outputTokens: 6450, reasoningTokens: 3184))
        ->and($generation->usage->total())->toBe(10411)
        ->and($generation->imageUrl)->toStartWith('https://pixelforge.promptedit.dev/images/')
        ->and($generation->imageUrlExpiresAt->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z')
        ->and($generation->startedAt->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z')
        ->and($generation->completedAt->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
        ->and($generation->idempotentReplayed)->toBeFalse();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
});

it('retrieves a failed generation with its error', function () {
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(PixelforgeFixtures::load('generation.failed'))]);

    $generation = client()->getGeneration('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->errorCode)->toBe('internal_error')
        ->and($generation->errorMessage)->toStartWith('Something went wrong on our side')
        ->and($generation->usage)->toBeNull();
});

it('lists generations with filters and a cursor', function () {
    Http::fake(['pixelforge.test/v1/generations*' => Http::response(PixelforgeFixtures::load('generations.list'))]);

    $page = client()->listGenerations(limit: 2, status: GenerationStatus::Succeeded, cursor: 'abc');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.test/v1/generations?limit=2&status=succeeded&cursor=abc');

    expect($page->data)->toHaveCount(2)
        ->and($page->data[0])->toBeInstanceOf(ProviderGeneration::class)
        ->and($page->data[0]->status)->toBe(GenerationStatus::Succeeded)
        ->and($page->hasMore)->toBeTrue()
        ->and($page->nextCursor)->toBe('eyJjcmVhdGVkX2F0Ijoi');
});

it('lists generations with the defaults', function () {
    Http::fake(['pixelforge.test/v1/generations*' => Http::response(PixelforgeFixtures::load('generations.list', ['has_more' => false, 'next_cursor' => null]))]);

    $page = client()->listGenerations();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.test/v1/generations?limit=20');

    expect($page->hasMore)->toBeFalse()->and($page->nextCursor)->toBeNull();
});

it('reads the current pricing without authenticating', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(PixelforgeFixtures::load('pricing'))]);

    $pricing = client()->getPricing();

    Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));

    expect($pricing->rates->inputText->toDecimal())->toBe('5.000000000')
        ->and($pricing->rates->inputImage->toDecimal())->toBe('10.000000000')
        ->and($pricing->rates->output->toDecimal())->toBe('40.000000000')
        ->and($pricing->rates->reasoning->toDecimal())->toBe('12.500000000')
        ->and($pricing->tokens->charactersPerTextToken)->toBe(4)
        ->and($pricing->tokens->textTokenVarianceBps)->toBe(1500)
        ->and($pricing->tokens->perReferenceImage)->toBe(765)
        ->and($pricing->tokens->outputTokens(Quality::High, Size::Landscape))->toBe(6450)
        ->and($pricing->tokens->outputTokens(Quality::Low, Size::Square))->toBe(280)
        ->and($pricing->tokens->minReasoningTokens(Quality::Medium))->toBe(200)
        ->and($pricing->tokens->maxReasoningTokens(Quality::High))->toBe(8000);
});

it('reads usage per UTC day for a date range', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(PixelforgeFixtures::load('usage'))]);

    $usage = client()->getUsage('2026-11-01', '2026-11-02');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.test/v1/usage?start_date=2026-11-01&end_date=2026-11-02'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));

    expect($usage->startDate)->toBe('2026-11-01')
        ->and($usage->endDate)->toBe('2026-11-02')
        ->and($usage->days)->toHaveCount(2)
        ->and($usage->days[0]->date)->toBe('2026-11-01')
        ->and($usage->days[0]->generations)->toBe(3)
        ->and($usage->days[0]->usage)->toEqual(new Usage(41, 0, 3300, 2950))
        ->and($usage->days[0]->cost)->toEqual(Money::of('0.169080000'))
        ->and($usage->days[1]->cost->isZero())->toBeTrue()
        ->and($usage->total->generations)->toBe(3)
        ->and($usage->total->cost->toDecimal())->toBe('0.169080000')
        ->and($usage->forDate('2026-11-01')?->generations)->toBe(3)
        ->and($usage->forDate('2026-10-01'))->toBeNull();
});

it('maps documented errors to typed exceptions', function (string $code, int $status, string $exception, ?int $retryAfter, bool $retryable) {
    Http::fake([
        'pixelforge.test/v1/generations' => Http::response(
            PixelforgeFixtures::load("errors/{$code}"),
            $status,
            $retryAfter === null ? [] : ['Retry-After' => (string) $retryAfter],
        ),
    ]);

    try {
        client()->createGeneration(['prompt' => 'x'], 'key');
        $this->fail('No exception was thrown.');
    } catch (PixelforgeException $e) {
        expect($e)->toBeInstanceOf($exception)
            ->and($e->errorCode)->toBe($code)
            ->and($e->status)->toBe($status)
            ->and($e->errorType)->toBe(PixelforgeFixtures::load("errors/{$code}")['error']['type'])
            ->and($e->getMessage())->toBe(PixelforgeFixtures::load("errors/{$code}")['error']['message'])
            ->and($e->retryAfter)->toBe($retryAfter)
            ->and($e->isRetryable())->toBe($retryable);
    }
})->with([
    ['invalid_api_key', 401, InvalidApiKey::class, null, false],
    ['validation_failed', 422, ValidationFailed::class, null, false],
    ['content_policy_violation', 400, ContentPolicyViolation::class, null, false],
    ['not_found', 404, NotFound::class, null, false],
    ['idempotency_key_reused', 409, IdempotencyKeyReused::class, null, false],
    ['rate_limit_exceeded', 429, RateLimited::class, 12, true],
    ['concurrency_limit_exceeded', 429, ConcurrencyLimited::class, 5, true],
    ['daily_limit_exceeded', 429, DailyLimitExceeded::class, null, false],
    ['overloaded', 503, Overloaded::class, 2, true],
    ['date_range_too_long', 422, DateRangeTooLong::class, null, false],
]);

it('exposes the invalid parameter and every validation problem', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(PixelforgeFixtures::load('errors/validation_failed'), 422)]);

    try {
        client()->createGeneration(['prompt' => 'x', 'size' => '1x1'], 'key');
    } catch (ValidationFailed $e) {
        expect($e->param)->toBe('size')
            ->and($e->errors)->toBe(['size' => ['The size must be one of: 1024x1024, 1536x1024, 1024x1536.']]);

        return;
    }

    $this->fail('ValidationFailed was not thrown.');
});

it('treats an unexpected server error as retryable', function () {
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response('<html>Bad gateway</html>', 502)]);

    expect(fn () => client()->getGeneration('gen_1'))->toThrow(function (ServerError $e) {
        expect($e->status)->toBe(502)
            ->and($e->errorCode)->toBe('server_error')
            ->and($e->isRetryable())->toBeTrue();
    });
});

it('treats an unknown client error as not retryable', function () {
    Http::fake(['pixelforge.test/v1/generations/*' => Http::response(['error' => ['type' => 'x', 'code' => 'something_new', 'message' => 'New.']], 418)]);

    expect(fn () => client()->getGeneration('gen_1'))->toThrow(function (PixelforgeException $e) {
        expect($e::class)->toBe(PixelforgeException::class)
            ->and($e->errorCode)->toBe('something_new')
            ->and($e->isRetryable())->toBeFalse();
    });
});

it('turns a connection failure into a retryable exception', function () {
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => client()->getPricing())->toThrow(function (ConnectionFailed $e) {
        expect($e->isRetryable())->toBeTrue()
            ->and($e->status)->toBeNull()
            ->and($e->getPrevious())->toBeInstanceOf(ConnectionException::class);
    });
});
