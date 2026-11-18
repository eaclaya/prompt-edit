<?php

use App\Enums\GenerationStatus;
use App\Generations\GenerationStateMachine;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Pixelforge\GenerationPayload;
use App\Pixelforge\PixelforgeClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['pixelforge.api_key' => 'pk_test', 'pixelforge.base_url' => 'https://pixelforge.test/v1']);
});

function runSubmit(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation->id))->withFakeQueueInteractions();
    $job->handle(app(PixelforgeClient::class), app(GenerationStateMachine::class));

    return $job;
}

function pixelforgeError(int $status, string $code, array $headers = []): PromiseInterface
{
    return Http::response(['error' => ['type' => 'x', 'code' => $code, 'message' => "Error: {$code}"]], $status, $headers);
}

it('submits a pending generation and records it as queued', function () {
    $generation = heldGeneration();
    Http::fake(['*/v1/generations' => Http::response(providerGeneration($generation, 'queued', ['id' => 'gen_new']), 202)]);

    $job = runSubmit($generation);

    $job->assertNotReleased();
    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->fresh()->provider_id)->toBe('gen_new')
        ->and($generation->fresh()->submit_attempts)->toBe(1)
        ->and($generation->fresh()->rates)->toBe(pixelforgePrices()->rates);
});

it('does nothing for a generation that is no longer pending', function () {
    $generation = heldGeneration(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_x']);
    Http::fake();

    runSubmit($generation);

    Http::assertNothingSent();
});

it('waits for a free slot instead of exceeding the concurrency limit', function () {
    Generation::factory()->count(3)->atProvider(GenerationStatus::Processing)->create();
    $generation = heldGeneration();
    Http::fake();

    runSubmit($generation)->assertReleased(delay: 5);

    Http::assertNothingSent();
    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
});

it('retries capacity errors after Retry-After', function (int $status, string $code, array $headers) {
    $generation = heldGeneration();
    Http::fake(['*' => pixelforgeError($status, $code, $headers)]);

    runSubmit($generation)->assertReleased();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
})->with([
    'rate limit' => [429, 'rate_limit_exceeded', ['Retry-After' => '20']],
    'concurrency' => [429, 'concurrency_limit_exceeded', []],
    'overloaded' => [503, 'overloaded', []],
]);

it('gives up on capacity errors after 15 minutes, free of charge', function () {
    $generation = heldGeneration();
    $generation->forceFill(['created_at' => now()->subMinutes(16)])->save();
    Http::fake(['*' => pixelforgeError(503, 'overloaded')]);

    runSubmit($generation)->assertNotReleased();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->fresh()->error_code)->toBe('retries_exhausted')
        ->and($generation->user->fresh()->balance_nano)->toBe(2_000_000_000);
});

it('keeps retrying a timeout, because the generation may exist', function () {
    $generation = heldGeneration();
    $generation->forceFill(['created_at' => now()->subHour()])->save();
    Http::fake(fn () => throw new ConnectionException('Operation timed out'));

    runSubmit($generation)->assertReleased();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
});

it('reuses the idempotency key, so a retried timeout creates one generation', function () {
    $generation = heldGeneration();
    $keys = [];
    Http::fake(function ($request) use (&$keys, $generation) {
        $keys[] = $request->header('Idempotency-Key')[0];

        if (count($keys) === 1) {
            throw new ConnectionException('Operation timed out');
        }

        return Http::response(providerGeneration($generation, 'queued', ['id' => 'gen_once']), 202, ['Idempotent-Replayed' => 'true']);
    });

    runSubmit($generation)->assertReleased();
    runSubmit($generation)->assertNotReleased();

    expect($keys)->toBe([$generation->id, $generation->id])
        ->and($generation->fresh()->provider_id)->toBe('gen_once');
});

it('fails definitive rejections immediately and for free', function (int $status, string $code) {
    $generation = heldGeneration();
    Http::fake(['*' => pixelforgeError($status, $code)]);

    runSubmit($generation)->assertNotReleased();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->fresh()->error_code)->toBe($code)
        ->and($generation->fresh()->price_nano)->toBe(0)
        ->and($generation->user->fresh()->balance_nano)->toBe(2_000_000_000);
})->with([
    'content policy' => [400, 'content_policy_violation'],
    'daily limit' => [429, 'daily_limit_exceeded'],
    'validation' => [422, 'validation_failed'],
    'idempotency conflict' => [409, 'idempotency_key_reused'],
    'bad api key' => [401, 'invalid_api_key'],
]);

it('handles a webhook that settled the generation before the create response', function () {
    $generation = heldGeneration();
    $succeeded = providerGeneration($generation, 'succeeded', ['id' => 'gen_fast']);
    Http::fake(function () use ($generation, $succeeded) {
        // The success webhook lands while the POST is still in flight.
        app(GenerationStateMachine::class)->apply(GenerationPayload::fromArray($succeeded));

        return Http::response(providerGeneration($generation, 'queued', ['id' => 'gen_fast']), 202);
    });

    runSubmit($generation);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->fresh()->price_nano)->toBe(SAMPLE_PRICE_NANO);
});
