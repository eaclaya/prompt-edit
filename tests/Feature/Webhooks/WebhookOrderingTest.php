<?php

use App\Contracts\GenerationSync;
use App\Enums\GenerationStatus;
use App\Jobs\ProcessWebhookEvent;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Pixelforge\Data\ProviderGeneration;
use App\Support\Money;
use Tests\Fakes\FakeCostCalculator;
use Tests\Fakes\FakeLedger;
use Tests\Fakes\FakeMarkup;
use Tests\Fixtures\PixelforgeFixtures;

// QUEUE_CONNECTION=sync: each accepted delivery is processed before the response returns.
beforeEach(function () {
    $this->ledger = FakeLedger::swap();
    $this->calculator = FakeCostCalculator::swap();
    FakeMarkup::swap();

    $this->user = User::factory()->withBalance('1.00')->create();
    $this->generation = Generation::factory()->queued()->for($this->user)->create();
    $this->ledger->hold($this->generation, $this->generation->est_max_nanos);
});

/** A webhook event for $generation, as Pixelforge would send it (local_id echoed in metadata). */
function eventFor(Generation $generation, string $type, ?string $eventId = null): array
{
    return PixelforgeFixtures::event($type, ['id' => $generation->provider_id ?? 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', 'metadata' => ['local_id' => $generation->id]], $eventId);
}

it('stores and processes the same evt_ delivered twice exactly once', function () {
    $event = eventFor($this->generation, 'generation.succeeded');

    postWebhook(signedWebhook($event, attempt: 1))->assertOk();
    postWebhook(signedWebhook($event, attempt: 2))->assertOk();

    expect(WebhookEvent::where('event_id', $event['id'])->count())->toBe(1)
        ->and(WebhookEvent::sole()->processed_at)->not->toBeNull()
        ->and($this->calculator->calls)->toHaveCount(1)
        ->and($this->ledger->settled)->toHaveCount(1);
});

it('ends succeeded with one charge after processing then succeeded', function () {
    postWebhook(eventFor($this->generation, 'generation.processing'))->assertOk();
    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Processing);

    postWebhook(eventFor($this->generation, 'generation.succeeded'))->assertOk();

    $fresh = $this->generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->charged_nanos)->toEqualMoney('0.3818875')
        ->and($this->ledger->settled)->toHaveCount(1);
    $this->ledger->assertSettled($this->generation, '0.3818875');
});

it('charges once when the same success arrives under two different event ids', function () {
    postWebhook(eventFor($this->generation, 'generation.succeeded'))->assertOk();
    postWebhook(eventFor($this->generation, 'generation.succeeded'))->assertOk();

    expect(WebhookEvent::count())->toBe(2)
        ->and($this->calculator->calls)->toHaveCount(1)
        ->and($this->ledger->settled)->toHaveCount(1);
});

it('stays succeeded when a late processing arrives', function () {
    postWebhook(eventFor($this->generation, 'generation.succeeded'))->assertOk();
    postWebhook(eventFor($this->generation, 'generation.processing'))->assertOk();

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($this->ledger->settled)->toHaveCount(1);
});

it('stays failed with $0 charged and the hold released when a late processing arrives', function () {
    postWebhook(eventFor($this->generation, 'generation.failed'))->assertOk();
    postWebhook(eventFor($this->generation, 'generation.processing'))->assertOk();

    $fresh = $this->generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Failed)
        ->and($fresh->charged_nanos)->toEqualMoney('0')
        ->and($this->ledger->available($fresh->user))->toEqualMoney($fresh->user->balance_nanos);
    $this->ledger->assertReleased($this->generation);
    $this->ledger->assertNotSettled($this->generation);
});

it('matches a success that arrives before the POST response by metadata.local_id', function () {
    $pending = Generation::factory()->for($this->user)->create(); // no provider_id yet
    $this->ledger->hold($pending, $pending->est_max_nanos);

    postWebhook(eventFor($pending, 'generation.succeeded'))->assertOk();

    $fresh = $pending->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->provider_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K');
    $this->ledger->assertSettled($pending, '0.3818875');

    // The POST response (202 queued) finally arrives through the same path and does not move it back.
    app(GenerationSync::class)->apply(ProviderGeneration::fromArray(PixelforgeFixtures::load('generation.queued', [
        'id' => 'gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K', 'metadata' => ['local_id' => $pending->id],
    ])));

    expect($pending->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($this->ledger->settled)->toHaveCount(1);
});

it('stores and acknowledges an event for an unknown generation without erroring', function () {
    $event = PixelforgeFixtures::event('generation.succeeded', ['id' => 'gen_nobody', 'metadata' => []]);

    postWebhook($event)->assertOk();

    $stored = WebhookEvent::sole();
    expect($stored->generation_provider_id)->toBe('gen_nobody')
        ->and($stored->processed_at)->not->toBeNull()
        ->and($this->ledger->settled)->toBe([]);
});

it('stores usage, image_url, expiry, provider cost, charge and completed_at on success', function () {
    postWebhook(eventFor($this->generation, 'generation.succeeded'))->assertOk();

    $fresh = $this->generation->fresh();
    expect($fresh->usage)->toEqual(['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411])
        ->and($fresh->image_url)->toContain('/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png')
        ->and($fresh->image_url_expires_at->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z')
        ->and($fresh->provider_cost_nanos)->toEqualMoney('0.305510')
        ->and($fresh->charged_nanos)->toEqualMoney('0.3818875')
        ->and($fresh->completed_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z');

    $this->ledger->assertSettled($this->generation, '0.3818875');
    expect($this->ledger->balance($fresh->user))->toEqualMoney($fresh->user->balance_nanos->subtract(Money::of('0.3818875')));
});

it('does nothing when the job runs again for an event already processed', function () {
    $event = eventFor($this->generation, 'generation.succeeded');
    postWebhook($event)->assertOk();
    $processedAt = WebhookEvent::sole()->processed_at;

    (new ProcessWebhookEvent($event['id']))->handle(app(GenerationSync::class));

    expect(WebhookEvent::sole()->processed_at->equalTo($processedAt))->toBeTrue()
        ->and($this->calculator->calls)->toHaveCount(1);
});

it('does nothing when the job runs for an event that is not stored', function () {
    (new ProcessWebhookEvent('evt_missing'))->handle(app(GenerationSync::class));

    expect($this->ledger->settled)->toBe([]);
});
