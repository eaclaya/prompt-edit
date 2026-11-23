<?php

use App\Contracts\GenerationSync;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Data\ProviderGeneration;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Redis\Limiters\DurationLimiter;
use Illuminate\Support\Facades\Redis;
use Tests\Fakes\FakeGenerationSync;
use Tests\Fakes\FakeLedger;
use Tests\Fakes\PixelforgeFake;

beforeEach(function () {
    // A Redis database of its own, so the dev stack's queue and limiters are never touched.
    config(['database.redis.default.database' => 15]);
    app('redis')->purge();
    Redis::flushdb();

    $this->pixelforge = PixelforgeFake::fake()->acceptsGeneration();
    FakeGenerationSync::swap();
    $this->ledger = FakeLedger::swap();
    $this->generation = Generation::factory()->for(User::factory()->withBalance('1.00'))->create();
    $this->ledger->hold($this->generation, $this->generation->est_max_nanos);
});

afterEach(function () {
    Redis::flushdb();
});

/** Run the job as the queue worker does: through its middleware, then handle(). */
function runQueued(Generation $generation, int $attempt = 1): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    $job->job->attempts = $attempt;

    (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(fn (SubmitGeneration $job) => app()->call([$job, 'handle']));

    return $job;
}

/** Pretend $count submissions are in flight right now (the funnel's slot keys). */
function occupyConcurrencySlots(int $count): void
{
    foreach (range(1, $count) as $slot) {
        Redis::set(SubmitGeneration::CONCURRENCY_KEY.$slot, "other-worker-{$slot}", 'EX', 60);
    }
}

function useRequestsThisMinute(int $count): void
{
    $limiter = new DurationLimiter(Redis::connection(), SubmitGeneration::RATE_KEY, (int) config('pixelforge.limits.requests_per_minute'), 60);

    foreach (range(1, $count) as $_) {
        $limiter->acquire();
    }
}

it('submits when the funnel has room and frees its slot afterwards', function () {
    occupyConcurrencySlots(2);

    $job = runQueued($this->generation);

    $this->pixelforge->assertCreatedTimes(1);
    $job->assertNotReleased();
    expect(Redis::exists(SubmitGeneration::CONCURRENCY_KEY.'3'))->toBe(0);
});

it('holds back a submission while 3 are already in flight', function () {
    occupyConcurrencySlots(3);

    $job = runQueued($this->generation);

    $this->pixelforge->assertNothingCreated();
    $job->assertReleased(delay: SubmitGeneration::BASE_BACKOFF);
    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Pending);
});

it('reads the concurrency cap from config', function () {
    config(['pixelforge.limits.max_concurrent' => 1]);
    occupyConcurrencySlots(1);

    runQueued($this->generation)->assertReleased();

    $this->pixelforge->assertNothingCreated();
});

it('allows 60 submissions a minute and holds back the 61st until the window resets', function () {
    useRequestsThisMinute(59);
    runQueued($this->generation)->assertNotReleased();
    $this->pixelforge->assertCreatedTimes(1);

    $job = runQueued($this->generation);

    $this->pixelforge->assertCreatedTimes(1);
    $job->assertReleased();
    expect($job->job->releaseDelay)->toBeGreaterThanOrEqual(SubmitGeneration::BASE_BACKOFF)->toBeLessThanOrEqual(60);
});

it('reads the per-minute cap from config', function () {
    config(['pixelforge.limits.requests_per_minute' => 2]);
    useRequestsThisMinute(2);

    runQueued($this->generation)->assertReleased();

    $this->pixelforge->assertNothingCreated();
});

it('does not spend a per-minute slot while waiting for a concurrency slot', function () {
    config(['pixelforge.limits.requests_per_minute' => 1]);
    occupyConcurrencySlots(3);
    runQueued($this->generation)->assertReleased();

    Redis::flushdb();
    runQueued($this->generation)->assertNotReleased();

    $this->pixelforge->assertCreatedTimes(1);
});

it('frees the concurrency slot when the submission throws', function () {
    occupyConcurrencySlots(2);
    app()->instance(GenerationSync::class, new class implements GenerationSync
    {
        public function apply(ProviderGeneration $g): ?Generation
        {
            throw new RuntimeException('Sync failed');
        }
    });

    expect(fn () => runQueued($this->generation))->toThrow(RuntimeException::class, 'Sync failed');
    expect(Redis::exists(SubmitGeneration::CONCURRENCY_KEY.'3'))->toBe(0);
});

it('fails cleanly when the funnel stays full until the last attempt', function () {
    occupyConcurrencySlots(3);

    $job = runQueued($this->generation, attempt: SubmitGeneration::MAX_ATTEMPTS);

    $job->assertNotReleased();
    $this->pixelforge->assertNothingCreated();
    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('submission_throttled');
    $this->ledger->assertReleased($this->generation);
});
