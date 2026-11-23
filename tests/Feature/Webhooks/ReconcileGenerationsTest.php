<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\FakeCostCalculator;
use Tests\Fakes\FakeLedger;
use Tests\Fakes\FakeMarkup;
use Tests\Fakes\PixelforgeFake;

beforeEach(function () {
    $this->ledger = FakeLedger::swap();
    FakeCostCalculator::swap();
    FakeMarkup::swap();
    $this->pixelforge = PixelforgeFake::fake();
    $this->user = User::factory()->withBalance('1.00')->create();
});

/** A generation last touched $seconds ago. */
function quietFor(int $seconds, string $state = 'processing', ?User $user = null): Generation
{
    $generation = Generation::factory()->{$state}()->for($user ?? User::factory()->withBalance('1.00'))->create();
    $generation->timestamps = false;
    $generation->forceFill(['updated_at' => now()->subSeconds($seconds)])->save();

    return $generation;
}

/** @return list<string> provider ids fetched with GET /generations/{id} */
function polled(): array
{
    return Http::recorded()
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (Request $r) => $r->method() === 'GET' && preg_match('#/generations/gen_#', $r->url()))
        ->map(fn (Request $r) => rawurldecode(basename((string) parse_url($r->url(), PHP_URL_PATH))))
        ->values()
        ->all();
}

it('polls only non-final generations that have gone quiet for more than 60 s', function () {
    $quietQueued = quietFor(120, 'queued', $this->user);
    $quietProcessing = quietFor(61, 'processing', $this->user);
    quietFor(30, 'processing', $this->user);          // recently updated
    quietFor(600, 'succeeded', $this->user);           // final
    quietFor(600, 'failed', $this->user);              // final
    quietFor(600, 'pending', $this->user);             // never accepted: no provider id to poll
    $this->pixelforge->returnsGeneration('generation.processing');

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect(polled())->toEqualCanonicalizing([$quietQueued->provider_id, $quietProcessing->provider_id]);
});

it('applies what it finds through the same sync: success settles, failure releases', function () {
    $won = quietFor(120, 'processing', $this->user);
    $lost = quietFor(180, 'processing', $this->user);
    $this->pixelforge->returnsGeneration('generation.failed')->returnsGeneration('generation.succeeded'); // oldest first

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($won->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($lost->fresh()->status)->toBe(GenerationStatus::Failed);
    $this->ledger->assertSettled($won, '0.3818875');
    $this->ledger->assertReleased($lost);
});

it('is idempotent when re-run', function () {
    $generation = quietFor(120, 'processing', $this->user);
    $this->pixelforge->returnsGeneration('generation.succeeded');

    $this->artisan('generations:reconcile')->assertSuccessful();
    $this->artisan('generations:reconcile')->assertSuccessful();

    expect(polled())->toBe([$generation->provider_id])
        ->and($this->ledger->settled)->toHaveCount(1);
});

it('polls a still-running generation again on the next run without changing it', function () {
    $generation = quietFor(120, 'processing', $this->user);
    $this->pixelforge->returnsGeneration('generation.processing');

    $this->artisan('generations:reconcile')->assertSuccessful();
    $this->artisan('generations:reconcile')->assertSuccessful();

    expect(polled())->toBe([$generation->provider_id, $generation->provider_id])
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);
});

it('moves on to the next generation when one is not found', function () {
    $missing = quietFor(180, 'processing', $this->user);
    $found = quietFor(120, 'processing', $this->user);
    $this->pixelforge->failsWith('not_found', endpoint: 'retrieve')->returnsGeneration('generation.succeeded');

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect($missing->fresh()->status)->toBe(GenerationStatus::Processing)
        ->and($found->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('stops the run when Pixelforge says to back off', function () {
    quietFor(180, 'processing', $this->user);
    quietFor(120, 'processing', $this->user);
    $this->pixelforge->failsWith('rate_limit_exceeded', endpoint: 'retrieve');

    $this->artisan('generations:reconcile')->assertSuccessful();

    expect(polled())->toHaveCount(1);
});

it('polls at most --limit generations, oldest first', function () {
    $oldest = quietFor(300, 'processing', $this->user);
    quietFor(200, 'processing', $this->user);
    $this->pixelforge->returnsGeneration('generation.processing');

    $this->artisan('generations:reconcile', ['--limit' => 1])->assertSuccessful();

    expect(polled())->toBe([$oldest->provider_id]);
});

it('is scheduled every minute without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'generations:reconcile'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('* * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});
