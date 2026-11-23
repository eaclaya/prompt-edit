<?php

use App\Contracts\GenerationSync;
use App\Enums\GenerationStatus;
use App\Generations\SyncGeneration;
use App\Models\Generation;
use App\Pixelforge\Data\ProviderGeneration;
use Illuminate\Support\Carbon;
use Tests\Fakes\FakeCostCalculator;
use Tests\Fakes\FakeLedger;
use Tests\Fakes\FakeMarkup;
use Tests\Fixtures\PixelforgeFixtures;

beforeEach(function () {
    $this->ledger = FakeLedger::swap();
    $this->calculator = FakeCostCalculator::swap();
    FakeMarkup::swap();
    Carbon::setTestNow('2026-11-04 14:05:00');
});

/** @param  array<string, mixed>  $overrides */
function provider(string $fixture, array $overrides = []): ProviderGeneration
{
    return ProviderGeneration::fromArray(PixelforgeFixtures::load($fixture, $overrides));
}

function sync(ProviderGeneration $generation): ?Generation
{
    return app(GenerationSync::class)->apply($generation);
}

it('is the bound GenerationSync', function () {
    expect(app(GenerationSync::class))->toBeInstanceOf(SyncGeneration::class);
});

it('returns null for a generation we do not know', function () {
    expect(sync(provider('generation.succeeded', ['metadata' => ['local_id' => '01KAB000000000000000000000']])))->toBeNull()
        ->and(sync(provider('generation.succeeded', ['metadata' => ['local_id' => 'not-a-ulid']])))->toBeNull()
        ->and(sync(provider('generation.processing')))->toBeNull();

    $this->ledger->assertNothingHeld();
    expect($this->ledger->settled)->toBe([]);
});

it('moves a pending generation to queued from the POST response, storing the provider id and rates', function () {
    $generation = Generation::factory()->create();

    $synced = sync(provider('generation.queued', ['id' => 'gen_abc', 'metadata' => ['local_id' => $generation->id]]));

    expect($synced->id)->toBe($generation->id)
        ->and($synced->status)->toBe(GenerationStatus::Queued)
        ->and($synced->provider_id)->toBe('gen_abc')
        ->and($synced->rates)->toEqual(['input_text_tokens' => '5.00', 'input_image_tokens' => '10.00', 'output_tokens' => '40.00', 'reasoning_tokens' => '12.50'])
        ->and($synced->submitted_at)->not->toBeNull()
        ->and($generation->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('matches on provider_id when the object has no local_id', function () {
    $generation = Generation::factory()->queued()->create(['provider_id' => 'gen_known']);

    $synced = sync(provider('generation.processing', ['id' => 'gen_known', 'metadata' => []]));

    expect($synced->id)->toBe($generation->id)
        ->and($synced->status)->toBe(GenerationStatus::Processing)
        ->and($synced->started_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:13Z');
});

it('prefers metadata.local_id over provider_id', function () {
    $mine = Generation::factory()->create();
    Generation::factory()->queued()->create(['provider_id' => 'gen_other']);

    expect(sync(provider('generation.queued', ['id' => 'gen_new', 'metadata' => ['local_id' => $mine->id]]))->id)->toBe($mine->id);
});

it('ignores a local_id whose row already belongs to another provider generation and falls back to provider_id', function () {
    $taken = Generation::factory()->queued()->create(['provider_id' => 'gen_first']);
    $right = Generation::factory()->queued()->create(['provider_id' => 'gen_second']);

    $synced = sync(provider('generation.processing', ['id' => 'gen_second', 'metadata' => ['local_id' => $taken->id]]));

    expect($synced->id)->toBe($right->id)
        ->and($taken->fresh()->status)->toBe(GenerationStatus::Queued);
});

it('stores usage, image, expiry, provider cost, charge and completed_at on success, and settles the charge', function () {
    $generation = Generation::factory()->processing()->create();

    $synced = sync(provider('generation.succeeded', ['id' => $generation->provider_id, 'metadata' => ['local_id' => $generation->id]]));

    expect($synced->status)->toBe(GenerationStatus::Succeeded)
        ->and($synced->usage)->toEqual(['input_text_tokens' => 12, 'input_image_tokens' => 765, 'output_tokens' => 6450, 'reasoning_tokens' => 3184, 'total_tokens' => 10411])
        ->and($synced->image_url)->toStartWith('https://pixelforge.promptedit.dev/images/gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K.png')
        ->and($synced->image_url_expires_at->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z')
        ->and($synced->completed_at->toIso8601ZuluString())->toBe('2026-11-04T14:03:29Z')
        ->and($synced->provider_cost_nanos)->toEqualMoney('0.305510')
        ->and($synced->charged_nanos)->toEqualMoney('0.3818875')
        ->and($synced->error_code)->toBeNull();

    $this->ledger->assertSettled($generation, '0.3818875');
    $this->ledger->assertNotReleased($generation);

    $fresh = $generation->fresh();
    expect($fresh->status)->toBe(GenerationStatus::Succeeded)
        ->and($fresh->charged_nanos)->toEqualMoney('0.3818875');
});

it('prices success with the rates carried by the generation', function () {
    $generation = Generation::factory()->processing()->create();

    sync(provider('generation.succeeded', [
        'id' => $generation->provider_id,
        'metadata' => ['local_id' => $generation->id],
        'rates' => ['output_tokens' => '80.00'],
    ]));

    expect($this->calculator->calls)->toHaveCount(1)
        ->and($this->calculator->calls[0]['rates']->toArray()['output_tokens'])->toBe('80.000000000');
});

it('records the error, charges nothing and releases the hold on failure', function () {
    $generation = Generation::factory()->processing()->create();

    $synced = sync(provider('generation.failed', ['id' => $generation->provider_id, 'metadata' => ['local_id' => $generation->id]]));

    expect($synced->status)->toBe(GenerationStatus::Failed)
        ->and($synced->error_code)->toBe('internal_error')
        ->and($synced->error_message)->not->toBeEmpty()
        ->and($synced->charged_nanos)->toEqualMoney('0')
        ->and($synced->provider_cost_nanos)->toEqualMoney('0')
        ->and($synced->completed_at)->not->toBeNull();

    $this->ledger->assertReleased($generation);
    $this->ledger->assertNotSettled($generation);
    expect($this->calculator->calls)->toBe([]);
});

it('leaves a final generation alone, whatever arrives later', function (string $final, string $late) {
    $generation = Generation::factory()->{$final}()->create();
    $before = $generation->fresh()->toArray();

    $synced = sync(provider("generation.{$late}", ['id' => $generation->provider_id, 'metadata' => ['local_id' => $generation->id]]));

    expect($synced->id)->toBe($generation->id)
        ->and($generation->fresh()->toArray())->toEqual($before)
        ->and($this->ledger->settled)->toBe([])
        ->and($this->ledger->released)->toBe([]);
})->with([
    'succeeded, then processing' => ['succeeded', 'processing'],
    'succeeded, then queued' => ['succeeded', 'queued'],
    'succeeded, then failed' => ['succeeded', 'failed'],
    'failed, then processing' => ['failed', 'processing'],
    'failed, then succeeded' => ['failed', 'succeeded'],
]);

it('does not move processing back to queued when the POST response arrives late', function () {
    $generation = Generation::factory()->processing()->create();

    expect(sync(provider('generation.queued', ['id' => $generation->provider_id, 'metadata' => ['local_id' => $generation->id]]))->status)
        ->toBe(GenerationStatus::Processing);
});

it('settles once when the same success is applied twice', function () {
    $generation = Generation::factory()->processing()->create();
    $succeeded = provider('generation.succeeded', ['id' => $generation->provider_id, 'metadata' => ['local_id' => $generation->id]]);

    sync($succeeded);
    sync($succeeded);

    expect($this->calculator->calls)->toHaveCount(1)
        ->and($this->ledger->settled)->toHaveCount(1);
});

it('jumps straight from pending to succeeded when the webhook beats the POST response', function () {
    $generation = Generation::factory()->create();

    $synced = sync(provider('generation.succeeded', ['metadata' => ['local_id' => $generation->id]]));

    expect($synced->status)->toBe(GenerationStatus::Succeeded)
        ->and($synced->provider_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($synced->rates)->not->toBeNull()
        ->and($synced->started_at)->not->toBeNull();

    $this->ledger->assertSettled($generation, '0.3818875');
});
