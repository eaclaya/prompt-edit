<?php

use App\Enums\GenerationStatus;
use App\Enums\LedgerType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\WebhookEvent;
use App\Pixelforge\SignatureVerifier;
use Illuminate\Testing\TestResponse;

const WEBHOOK_SECRET = 'whsec_test_secret';

beforeEach(function () {
    config(['pixelforge.webhook_secret' => WEBHOOK_SECRET]);
});

/**
 * POST a webhook exactly as Pixelforge does: raw JSON body, signed header.
 *
 * @param  array<string, mixed>  $event
 */
function deliver(array $event, ?int $timestamp = null, ?string $signature = null): TestResponse
{
    $body = json_encode($event, JSON_THROW_ON_ERROR);
    $timestamp ??= time();
    $signature ??= SignatureVerifier::sign($timestamp, $body, WEBHOOK_SECRET);

    return test()->call('POST', '/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1={$signature}",
        'HTTP_PIXELFORGE_EVENT_ID' => $event['id'],
    ], content: $body);
}

function linkedGeneration(): Generation
{
    return tap(heldGeneration(), fn (Generation $g) => $g->forceFill([
        'provider_id' => 'gen_'.strtolower($g->id),
        'status' => GenerationStatus::Queued,
    ])->save());
}

it('applies a signed event', function () {
    $generation = linkedGeneration();

    deliver(webhookEvent('generation.processing', providerGeneration($generation, 'processing')))
        ->assertOk()
        ->assertJson(['status' => 'queued']);

    expect($generation->fresh()->status)->toBe(GenerationStatus::Processing)
        ->and(WebhookEvent::sole()->processed_at)->not->toBeNull();
});

it('charges once when the same event is delivered twice', function () {
    $generation = linkedGeneration();
    $event = webhookEvent('generation.succeeded', providerGeneration($generation, 'succeeded'));

    deliver($event)->assertOk();
    deliver($event)->assertOk()->assertJson(['status' => 'duplicate']);

    expect(WebhookEvent::sole()->attempts_seen)->toBe(2)
        ->and(LedgerEntry::where('generation_id', $generation->id)->where('type', LedgerType::Charge)->count())->toBe(1)
        ->and($generation->user->fresh()->balance_nano)->toBe(2_000_000_000 - SAMPLE_PRICE_NANO);
});

it('charges once when two different events report the same success', function () {
    $generation = linkedGeneration();

    deliver(webhookEvent('generation.succeeded', providerGeneration($generation, 'succeeded')))->assertOk();
    deliver(webhookEvent('generation.succeeded', providerGeneration($generation, 'succeeded')))->assertOk();

    expect(LedgerEntry::where('generation_id', $generation->id)->where('type', LedgerType::Charge)->count())->toBe(1);
});

it('reaches the right state when events arrive out of order', function () {
    $generation = linkedGeneration();

    deliver(webhookEvent('generation.succeeded', providerGeneration($generation, 'succeeded')))->assertOk();
    deliver(webhookEvent('generation.processing', providerGeneration($generation, 'processing')))->assertOk();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->fresh()->price_nano)->toBe(SAMPLE_PRICE_NANO);
});

it('makes a failed generation free', function () {
    $generation = linkedGeneration();

    deliver(webhookEvent('generation.failed', providerGeneration($generation, 'failed')))->assertOk();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->user->fresh()->balance_nano)->toBe(2_000_000_000);
});

it('matches a webhook that arrives before the create response', function () {
    $generation = heldGeneration(); // pending, no provider id yet

    deliver(webhookEvent('generation.processing', providerGeneration($generation, 'processing', ['id' => 'gen_early'])))->assertOk();

    expect($generation->fresh()->provider_id)->toBe('gen_early')
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Processing);
});

it('rejects a bad signature', function () {
    $generation = linkedGeneration();

    deliver(webhookEvent('generation.succeeded', providerGeneration($generation, 'succeeded')), signature: str_repeat('0', 64))
        ->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0)
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('rejects a signature made with another secret', function () {
    $event = webhookEvent('generation.processing', pixelforgeGeneration());
    $body = json_encode($event);
    $timestamp = time();

    deliver($event, $timestamp, SignatureVerifier::sign($timestamp, $body, 'whsec_other'))->assertStatus(400);
});

it('rejects a replayed old delivery', function () {
    deliver(webhookEvent('generation.processing', pixelforgeGeneration()), timestamp: time() - 301)
        ->assertStatus(400);
});

it('rejects a tampered body', function () {
    $event = webhookEvent('generation.processing', pixelforgeGeneration());
    $timestamp = time();
    $signature = SignatureVerifier::sign($timestamp, json_encode($event), WEBHOOK_SECRET);
    $event['data']['object']['status'] = 'succeeded';

    deliver($event, $timestamp, $signature)->assertStatus(400);
});

it('answers ping events', function () {
    deliver(['id' => 'evt_ping', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-18T14:00:00Z', 'data' => []])
        ->assertOk();

    expect(WebhookEvent::count())->toBe(0);
});

it('accepts events for generations it does not know', function () {
    deliver(webhookEvent('generation.succeeded', pixelforgeGeneration(['id' => 'gen_other_env', 'status' => 'processing'])))
        ->assertOk();

    expect(WebhookEvent::sole()->processed_at)->not->toBeNull();
});
