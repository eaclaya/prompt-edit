<?php

use App\Enums\GenerationStatus;
use App\Enums\LedgerType;
use App\Generations\GenerationStateMachine;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Pixelforge\GenerationPayload;

beforeEach(function () {
    $this->machine = app(GenerationStateMachine::class);
});

function applyStatus(Generation $generation, string $status, array $overrides = []): ?Generation
{
    return app(GenerationStateMachine::class)->apply(
        GenerationPayload::fromArray(providerGeneration($generation, $status, $overrides))
    );
}

it('moves a generation forward through its statuses', function () {
    $generation = heldGeneration();

    expect(applyStatus($generation, 'queued')->status)->toBe(GenerationStatus::Queued)
        ->and(applyStatus($generation, 'processing')->status)->toBe(GenerationStatus::Processing)
        ->and(applyStatus($generation, 'succeeded')->status)->toBe(GenerationStatus::Succeeded);
});

it('charges the actual cost plus 25% on success and releases the hold', function () {
    $generation = heldGeneration();
    $user = $generation->user;

    applyStatus($generation, 'succeeded');
    $generation->refresh();

    expect($generation->provider_cost_nano)->toBe(SAMPLE_COST_NANO)
        ->and($generation->price_nano)->toBe(SAMPLE_PRICE_NANO)
        ->and($generation->output_tokens)->toBe(1100)
        ->and($generation->image_url)->not->toBeNull()
        ->and($generation->completed_at->toIso8601ZuluString())->toBe('2026-11-18T14:03:40Z')
        ->and($user->fresh()->balance_nano)->toBe(2_000_000_000 - SAMPLE_PRICE_NANO);

    $charge = LedgerEntry::where('generation_id', $generation->id)->where('type', LedgerType::Charge)->sole();
    expect($charge->provider_cost_nano)->toBe(SAMPLE_COST_NANO)
        ->and($charge->occurred_at->toIso8601ZuluString())->toBe('2026-11-18T14:03:40Z');
});

it('uses the rates on the generation, not the current price list', function () {
    $generation = heldGeneration();
    $rates = [...pixelforgePrices()->rates, 'output_tokens' => '20.00'];

    applyStatus($generation, 'succeeded', ['rates' => $rates]);

    // output at $20 instead of $40: 1100 tokens cost 22,000,000 less
    expect($generation->fresh()->provider_cost_nano)->toBe(SAMPLE_COST_NANO - 22_000_000);
});

it('makes a failed generation free', function () {
    $generation = heldGeneration();
    $user = $generation->user;

    applyStatus($generation, 'failed');
    $generation->refresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->price_nano)->toBe(0)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($user->fresh()->balance_nano)->toBe(2_000_000_000)
        ->and(LedgerEntry::where('generation_id', $generation->id)->where('type', LedgerType::Charge)->exists())->toBeFalse();
});

it('records a success delivered twice only once', function () {
    $generation = heldGeneration();

    applyStatus($generation, 'succeeded');
    applyStatus($generation, 'succeeded');

    expect(LedgerEntry::where('generation_id', $generation->id)->count())->toBe(3) // hold, release, charge
        ->and($generation->user->fresh()->balance_nano)->toBe(2_000_000_000 - SAMPLE_PRICE_NANO);
});

it('ignores a late "processing" after success', function () {
    $generation = heldGeneration();

    applyStatus($generation, 'succeeded');
    applyStatus($generation, 'processing');
    applyStatus($generation, 'queued');

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

it('never turns a success into a failure or back', function () {
    $succeeded = heldGeneration();
    applyStatus($succeeded, 'succeeded');
    applyStatus($succeeded, 'failed');

    $failed = heldGeneration();
    applyStatus($failed, 'failed');
    applyStatus($failed, 'succeeded');

    expect($succeeded->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($succeeded->fresh()->price_nano)->toBe(SAMPLE_PRICE_NANO)
        ->and($failed->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($failed->fresh()->price_nano)->toBe(0);
});

it('accepts a success that skipped the earlier statuses', function () {
    $generation = heldGeneration(); // still pending: the webhook beat the create response

    applyStatus($generation, 'succeeded');

    expect($generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and($generation->fresh()->provider_id)->toBe('gen_'.strtolower($generation->id));
});

it('matches a generation by provider id once it is known', function () {
    $generation = heldGeneration(['provider_id' => 'gen_known']);

    $updated = applyStatus($generation, 'processing', ['metadata' => []]);

    expect($updated?->id)->toBe($generation->id);
});

it('ignores generations that are not ours', function () {
    $payload = GenerationPayload::fromArray(pixelforgeGeneration(['id' => 'gen_stranger', 'metadata' => []]));

    expect($this->machine->apply($payload))->toBeNull();
});

it('does not let a local id claim a generation already linked elsewhere', function () {
    $generation = heldGeneration(['provider_id' => 'gen_original']);

    $result = applyStatus($generation, 'succeeded', ['id' => 'gen_other']);

    expect($result)->toBeNull()
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Pending);
});

it('fails a pending generation locally for free', function () {
    $generation = heldGeneration();

    $this->machine->failLocally($generation, 'content_policy_violation', 'Blocked.');

    expect($generation->fresh()->status)->toBe(GenerationStatus::Failed)
        ->and($generation->fresh()->price_nano)->toBe(0)
        ->and($generation->user->fresh()->balance_nano)->toBe(2_000_000_000);
});

it('never fails a generation locally once Pixelforge has it', function () {
    $generation = heldGeneration();
    applyStatus($generation, 'queued');

    $this->machine->failLocally($generation->fresh(), 'retries_exhausted', 'Too busy.');

    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
});
