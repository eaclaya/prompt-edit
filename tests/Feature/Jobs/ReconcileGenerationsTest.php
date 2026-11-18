<?php

use App\Enums\GenerationStatus;
use App\Generations\GenerationStateMachine;
use App\Jobs\ProcessWebhookEvent;
use App\Jobs\ReconcileGenerations;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use App\Pixelforge\GenerationPayload;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config(['pixelforge.api_key' => 'pk_test', 'pixelforge.base_url' => 'https://pixelforge.test/v1']);
});

it('polls generations it has not heard about recently', function () {
    Queue::fake();
    $stale = Generation::factory()->atProvider(GenerationStatus::Processing)->create();
    $stale->forceFill(['updated_at' => now()->subMinutes(5), 'last_synced_at' => now()->subMinutes(5)])->saveQuietly();
    Generation::factory()->atProvider(GenerationStatus::Processing)->create(); // fresh
    Generation::factory()->atProvider(GenerationStatus::Succeeded)->create(['updated_at' => now()->subHour()]);

    (new ReconcileGenerations)->handle();

    Queue::assertPushed(SyncGeneration::class, 1);
    Queue::assertPushed(SyncGeneration::class, fn ($job) => $job->generationId === $stale->id);
});

it('resubmits a pending generation whose job was lost', function () {
    Queue::fake();
    $stuck = heldGeneration();
    $stuck->forceFill(['updated_at' => now()->subMinutes(3)])->saveQuietly();
    heldGeneration(); // just created, its job is still on the way

    (new ReconcileGenerations)->handle();

    Queue::assertPushed(SubmitGeneration::class, 1);
    Queue::assertPushed(SubmitGeneration::class, fn ($job) => $job->generationId === $stuck->id);
});

it('reprocesses webhook events that were never processed', function () {
    Queue::fake();
    WebhookEvent::create([
        'event_id' => 'evt_stuck',
        'type' => 'generation.processing',
        'payload' => webhookEvent('generation.processing', pixelforgeGeneration(), 'evt_stuck'),
        'received_at' => now()->subMinutes(5),
    ]);

    (new ReconcileGenerations)->handle();

    Queue::assertPushed(ProcessWebhookEvent::class, fn ($job) => $job->eventId === 'evt_stuck');
});

it('settles a generation whose webhooks never arrived', function () {
    $generation = heldGeneration(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_lost']);
    Http::fake(['*/v1/generations/gen_lost' => Http::response(providerGeneration($generation, 'succeeded'))]);

    (new SyncGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationStateMachine::class));

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->fresh()->price_nano)->toBe(SAMPLE_PRICE_NANO);
});

it('charges once when polling and a webhook both report success', function () {
    $generation = heldGeneration(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_both']);
    $succeeded = providerGeneration($generation, 'succeeded');
    Http::fake(['*' => Http::response($succeeded)]);

    app(GenerationStateMachine::class)->apply(GenerationPayload::fromArray($succeeded));
    (new SyncGeneration($generation->id))->handle(app(PixelforgeClient::class), app(GenerationStateMachine::class));

    Http::assertNothingSent(); // already final, no need to poll
    expect($generation->user->fresh()->balance_nano)->toBe(2_000_000_000 - SAMPLE_PRICE_NANO);
});
