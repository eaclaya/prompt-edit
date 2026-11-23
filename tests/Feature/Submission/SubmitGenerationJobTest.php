<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Data\ProviderGeneration;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeGenerationSync;
use Tests\Fakes\FakeLedger;
use Tests\Fakes\PixelforgeFake;
use Tests\Fixtures\PixelforgeFixtures;

beforeEach(function () {
    Carbon::setTestNow('2026-11-23 10:00:00');
    $this->pixelforge = PixelforgeFake::fake();
    $this->sync = FakeGenerationSync::swap()->writeThrough();
    $this->ledger = FakeLedger::swap();

    $this->generation = Generation::factory()
        ->for(User::factory()->withBalance('1.00'))
        ->create([
            'prompt' => 'A lighthouse at dusk',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/ref.png'],
            'est_max_nanos' => 300_000_000,
        ]);
    $this->ledger->hold($this->generation, $this->generation->est_max_nanos);
});

/** Run the job's handle() (the funnel middleware is tested separately) on the given attempt. */
function runSubmission(Generation $generation, int $attempt = 1): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;

    app()->call([$job, 'handle']);

    return $job;
}

it('sends only the allowed fields with the ULID as Idempotency-Key and metadata.local_id', function () {
    $this->pixelforge->acceptsGeneration();

    runSubmission($this->generation);

    $this->pixelforge->assertCreatedTimes(1);
    $this->pixelforge->assertCreated(function (array $body, array $headers) {
        return $headers['Idempotency-Key'] === $this->generation->id
            && $body === [
                'prompt' => 'A lighthouse at dusk',
                'size' => '1536x1024',
                'quality' => 'high',
                'reference_images' => ['https://example.com/ref.png'],
                'metadata' => ['local_id' => $this->generation->id],
            ];
    });
});

it('uses the stored idempotency key on every attempt', function () {
    $this->generation->forceFill(['idempotency_key' => 'key-from-first-attempt'])->save();
    $this->pixelforge->acceptsGeneration();

    runSubmission($this->generation, attempt: 3);

    $this->pixelforge->assertCreated(fn (array $body, array $headers) => $headers['Idempotency-Key'] === 'key-from-first-attempt'
        && $body['metadata'] === ['local_id' => $this->generation->id]);
});

it('stores provider_id, rates and submitted_at and moves to queued through GenerationSync on a 202', function () {
    $this->pixelforge->acceptsGeneration(['id' => 'gen_ACCEPTED']);

    $job = runSubmission($this->generation);

    $this->sync->assertAppliedTimes(1);
    $this->sync->assertApplied(fn (ProviderGeneration $g) => $g->id === 'gen_ACCEPTED'
        && $g->localId() === $this->generation->id
        && $g->status === GenerationStatus::Queued);

    $generation = $this->generation->fresh();
    expect($generation->provider_id)->toBe('gen_ACCEPTED')
        ->and($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->rates)->toEqual(PixelforgeFixtures::load('generation.queued')['rates'])
        ->and($generation->submitted_at?->toIso8601String())->toBe(now()->toIso8601String());

    $this->ledger->assertNotReleased($generation);
    $this->ledger->assertNotSettled($generation);
    $job->assertNotReleased();
    $job->assertNotFailed();
});

it('stores provider_id and rates itself even when GenerationSync does not write them', function () {
    $this->sync->writeThrough(false);
    $this->pixelforge->acceptsGeneration(['id' => 'gen_ACCEPTED']);

    runSubmission($this->generation);

    expect($this->generation->fresh())
        ->provider_id->toBe('gen_ACCEPTED')
        ->rates->toEqual(PixelforgeFixtures::load('generation.queued')['rates'])
        ->status->toBe(GenerationStatus::Pending);
});

it('treats an Idempotent-Replayed 202 as the same generation: no new hold, no new rows', function () {
    $this->generation->forceFill(['provider_id' => 'gen_FIRST', 'submitted_at' => now()->subMinute()])->save();
    $this->pixelforge->acceptsGeneration(['id' => 'gen_FIRST', 'status' => 'processing'], replayed: true);
    Carbon::setTestNow(now()->addMinutes(5));

    runSubmission($this->generation, attempt: 2);

    $this->sync->assertApplied(fn (ProviderGeneration $g) => $g->idempotentReplayed && $g->id === 'gen_FIRST');
    expect(Generation::count())->toBe(1)
        ->and($this->ledger->held)->toHaveCount(1)
        ->and($this->generation->fresh())
        ->provider_id->toBe('gen_FIRST')
        ->status->toBe(GenerationStatus::Processing)
        ->submitted_at->toIso8601String()->toBe(now()->subMinutes(6)->toIso8601String());
    $this->ledger->assertHeld($this->generation, '0.30');
    $this->ledger->assertNotReleased($this->generation);
    $this->ledger->assertNotSettled($this->generation);
});

it('does not move a generation back when the webhook already moved it forward', function () {
    // The succeeded webhook won the race against the POST response.
    $this->generation->forceFill([
        'provider_id' => 'gen_RACE',
        'status' => GenerationStatus::Succeeded,
        'rates' => ['output_tokens' => '40.00'],
        'image_url' => 'https://cdn.example.com/img.png',
        'completed_at' => now(),
    ])->save();
    $this->pixelforge->acceptsGeneration(['id' => 'gen_RACE']);

    runSubmission($this->generation);

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_id->toBe('gen_RACE')
        ->rates->toEqual(['output_tokens' => '40.00'])
        ->image_url->toBe('https://cdn.example.com/img.png');
});

it('releases the job back to the queue after Retry-After on a retryable error', function (string $code, int $retryAfter) {
    $this->pixelforge->failsWith($code, retryAfter: $retryAfter);

    $job = runSubmission($this->generation);

    $job->assertReleased(delay: $retryAfter);
    $job->assertNotFailed();
    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Pending);
    $this->ledger->assertNotReleased($this->generation);
    $this->sync->assertNothingApplied();
})->with([
    '429 rate_limit_exceeded' => ['rate_limit_exceeded', 60],
    '429 concurrency_limit_exceeded' => ['concurrency_limit_exceeded', 5],
    '503 overloaded' => ['overloaded', 7],
]);

it('backs off exponentially, never sooner than Retry-After, capped', function (int $attempt, ?int $retryAfter, int $expectedDelay) {
    $retryAfter === null
        ? $this->pixelforge->failsToConnect()
        : $this->pixelforge->failsWith('overloaded', retryAfter: $retryAfter);

    runSubmission($this->generation, $attempt)->assertReleased(delay: $expectedDelay);
})->with([
    'attempt 1, Retry-After 2' => [1, 2, 5],
    'attempt 3, Retry-After 2' => [3, 2, 20],
    'attempt 3, Retry-After 60' => [3, 60, 60],
    'attempt 8, capped' => [8, 2, 300],
    'attempt 2, connection failed' => [2, null, 10],
]);

it('retries a connection failure or a 5xx', function () {
    $this->pixelforge->failsToConnect();
    runSubmission($this->generation)->assertReleased();

    Http::swap(new HttpFactory);
    Http::fake(['*' => Http::response(['error' => ['type' => 'api_error', 'message' => 'Bad gateway']], 502)]);
    runSubmission($this->generation)->assertReleased();

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Pending);
});

it('fails cleanly once the retry cap is hit: marks the generation failed and releases the hold', function () {
    $this->pixelforge->failsWith('overloaded', retryAfter: 2);

    $job = runSubmission($this->generation, attempt: SubmitGeneration::MAX_ATTEMPTS);

    $job->assertNotReleased();
    $job->assertNotFailed();
    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('overloaded')
        ->error_message->toContain('Pixelforge is temporarily overloaded');
    $this->ledger->assertReleased($this->generation);
    $this->ledger->assertNotSettled($this->generation);
});

it('marks the generation failed with the error and releases the hold on a non-retryable error', function (string $code, string $message) {
    $this->pixelforge->failsWith($code);

    $job = runSubmission($this->generation);

    $job->assertNotReleased();
    $job->assertNotFailed();
    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe($code)
        ->error_message->toBe($message)
        ->provider_id->toBeNull();
    $this->ledger->assertReleased($this->generation);
    $this->ledger->assertNotSettled($this->generation);
    $this->sync->assertNothingApplied();
})->with([
    '400 content_policy_violation' => ['content_policy_violation', PixelforgeFixtures::load('errors/content_policy_violation')['error']['message']],
    '422 validation_failed' => ['validation_failed', 'The selected size is invalid.'],
    '429 daily_limit_exceeded' => ['daily_limit_exceeded', PixelforgeFixtures::load('errors/daily_limit_exceeded')['error']['message']],
]);

it('leaves a generation the webhook already moved forward alone when the POST then errors', function () {
    // An earlier attempt timed out after Pixelforge accepted it; the webhook already arrived.
    $this->generation->forceFill(['provider_id' => 'gen_EARLIER', 'status' => GenerationStatus::Processing])->save();
    $this->pixelforge->failsWith('overloaded');

    $job = runSubmission($this->generation, attempt: SubmitGeneration::MAX_ATTEMPTS);

    $job->assertNotReleased();
    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Processing);
    $this->ledger->assertNotReleased($this->generation);
});

it('sends nothing for a generation that is already final', function () {
    $this->generation->forceFill(['status' => GenerationStatus::Failed])->save();

    runSubmission($this->generation);

    $this->pixelforge->assertNothingCreated();
});

it('sends nothing for a generation that was deleted', function () {
    $job = (new SubmitGeneration($this->generation))->withFakeQueueInteractions();
    $this->generation->delete();

    app()->call([$job, 'handle']);

    $this->pixelforge->assertNothingCreated();
});

it('marks a still-pending generation failed and releases the hold when the job dies unexpectedly', function () {
    (new SubmitGeneration($this->generation))->failed(new RuntimeException('Worker crashed'));

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('submission_failed');
    $this->ledger->assertReleased($this->generation);
});

it('does not fail a generation Pixelforge already accepted when the job dies afterwards', function () {
    $this->generation->forceFill(['provider_id' => 'gen_ACCEPTED'])->save();

    (new SubmitGeneration($this->generation))->failed(new RuntimeException('Sync blew up'));

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Pending);
    $this->ledger->assertNotReleased($this->generation);
});
