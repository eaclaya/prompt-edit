<?php

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['pixelforge.api_key' => 'pk_test_123', 'pixelforge.base_url' => 'https://pixelforge.test/v1']);
    $this->client = app(PixelforgeClient::class);
});

function errorResponse(int $status, string $code, array $headers = []): Response|PromiseInterface
{
    return Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => "Error {$code}"]], $status, $headers);
}

it('submits a generation with our ULID as the idempotency key', function () {
    $generation = Generation::factory()->create([
        'prompt' => 'a lighthouse at dusk',
        'size' => Size::Landscape,
        'quality' => Quality::High,
        'reference_images' => ['https://example.com/a.png'],
    ]);
    Http::fake(['*/v1/generations' => Http::response(pixelforgeGeneration([
        'metadata' => ['local_id' => $generation->id],
    ]), 202)]);

    $payload = $this->client->createGeneration($generation);

    expect($payload->status)->toBe(GenerationStatus::Queued)
        ->and($payload->localId())->toBe($generation->id)
        ->and($payload->replayed)->toBeFalse();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->hasHeader('Authorization', 'Bearer pk_test_123')
        && $request->hasHeader('Idempotency-Key', $generation->id)
        && $request['prompt'] === 'a lighthouse at dusk'
        && $request['size'] === '1536x1024'
        && $request['quality'] === 'high'
        && $request['reference_images'] === ['https://example.com/a.png']
        && $request['metadata'] === ['local_id' => $generation->id, 'user_id' => (string) $generation->user_id]);
});

it('recognises an idempotent replay', function () {
    $generation = Generation::factory()->create();
    Http::fake(['*' => Http::response(pixelforgeGeneration(['status' => 'processing']), 202, ['Idempotent-Replayed' => 'true'])]);

    $payload = $this->client->createGeneration($generation);

    expect($payload->replayed)->toBeTrue()
        ->and($payload->status)->toBe(GenerationStatus::Processing);
});

it('parses a succeeded generation', function () {
    Http::fake(['*/v1/generations/gen_abc' => Http::response(pixelforgeGeneration([
        'id' => 'gen_abc',
        'status' => 'succeeded',
        'image_url' => 'https://pixelforge.test/images/gen_abc.png?expires=1&signature=x',
        'image_url_expires_at' => '2026-11-19T14:03:40Z',
        'usage' => ['input_text_tokens' => 41, 'input_image_tokens' => 0, 'output_tokens' => 3300, 'reasoning_tokens' => 2950, 'total_tokens' => 6291],
        'started_at' => '2026-11-18T14:03:14Z',
        'completed_at' => '2026-11-18T14:03:40Z',
    ]))]);

    $payload = $this->client->getGeneration('gen_abc');

    expect($payload->status)->toBe(GenerationStatus::Succeeded)
        ->and($payload->usage->output)->toBe(3300)
        ->and($payload->rates['reasoning_tokens'])->toBe('12.50')
        ->and($payload->completedAt->toIso8601ZuluString())->toBe('2026-11-18T14:03:40Z')
        ->and($payload->imageUrlExpiresAt->toIso8601ZuluString())->toBe('2026-11-19T14:03:40Z');
});

it('parses a failed generation', function () {
    Http::fake(['*' => Http::response(pixelforgeGeneration([
        'status' => 'failed',
        'error' => ['code' => 'generation_failed', 'message' => 'The model could not produce an image.'],
        'completed_at' => '2026-11-18T14:03:40Z',
    ]))]);

    $payload = $this->client->getGeneration('gen_01jcz8xk4m2n5p7q9r3s6t8v0w');

    expect($payload->status)->toBe(GenerationStatus::Failed)
        ->and($payload->errorCode)->toBe('generation_failed')
        ->and($payload->usage)->toBeNull();
});

it('maps API errors to exceptions with their code and retry hint', function (int $status, string $code, array $headers, ?int $retryAfter, bool $retryable) {
    Http::fake(['*' => errorResponse($status, $code, $headers)]);

    try {
        $this->client->createGeneration(Generation::factory()->create());
        $this->fail('Expected a PixelforgeException.');
    } catch (PixelforgeException $e) {
        expect($e->errorCode)->toBe($code)
            ->and($e->status)->toBe($status)
            ->and($e->retryAfter)->toBe($retryAfter)
            ->and($e->isRetryable())->toBe($retryable);
    }
})->with([
    'rate limit with header' => [429, 'rate_limit_exceeded', ['Retry-After' => '17'], 17, true],
    'concurrency, docs default' => [429, 'concurrency_limit_exceeded', [], 5, true],
    'overloaded, docs default' => [503, 'overloaded', [], 2, true],
    'overloaded with header' => [503, 'overloaded', ['Retry-After' => '4'], 4, true],
    'daily limit' => [429, 'daily_limit_exceeded', [], null, false],
    'content policy' => [400, 'content_policy_violation', [], null, false],
    'validation' => [422, 'validation_failed', [], null, false],
    'idempotency reuse' => [409, 'idempotency_key_reused', [], null, false],
    'bad key' => [401, 'invalid_api_key', [], null, false],
]);

it('treats an unrecognised server error as retryable', function () {
    Http::fake(['*' => Http::response('Bad Gateway', 502)]);

    try {
        $this->client->getGeneration('gen_x');
        $this->fail('Expected a PixelforgeException.');
    } catch (PixelforgeException $e) {
        expect($e->errorCode)->toBe(PixelforgeException::UNEXPECTED_RESPONSE)
            ->and($e->isRetryable())->toBeTrue();
    }
});

it('treats a timeout as retryable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    try {
        $this->client->createGeneration(Generation::factory()->create());
        $this->fail('Expected a PixelforgeException.');
    } catch (PixelforgeException $e) {
        expect($e->errorCode)->toBe(PixelforgeException::CONNECTION_FAILED)
            ->and($e->isRetryable())->toBeTrue();
    }
});

it('requests usage for a date range', function () {
    Http::fake(['*/v1/usage*' => Http::response(['object' => 'usage', 'data' => [], 'total' => []])]);

    $this->client->usage(now()->parse('2026-11-01'), now()->parse('2026-11-18'));

    Http::assertSent(fn (Request $request) => $request->url() === 'https://pixelforge.test/v1/usage?start_date=2026-11-01&end_date=2026-11-18');
});
