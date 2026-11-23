<?php

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

it('gives users an admin flag and a cached balance in nanodollars', function () {
    $user = User::factory()->create()->fresh();

    expect($user->is_admin)->toBeFalse()
        ->and($user->balance_nanos)->toEqual(Money::zero())
        ->and(User::factory()->admin()->create()->is_admin)->toBeTrue();

    $user->forceFill(['balance_nanos' => Money::of('1.25')])->save();

    expect($user->fresh()->balance_nanos->toDecimal())->toBe('1.250000000');
});

it('stores a pending generation with a ULID, its own idempotency key and casts', function () {
    $generation = Generation::factory()->create([
        'size' => Size::Landscape,
        'quality' => Quality::High,
        'reference_images' => ['https://example.com/style.png'],
        'est_max_nanos' => Money::of('0.373875'),
    ])->fresh();

    expect(Str::isUlid($generation->id))->toBeTrue()
        ->and($generation->idempotency_key)->toBe($generation->id)
        ->and($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->size)->toBe(Size::Landscape)
        ->and($generation->quality)->toBe(Quality::High)
        ->and($generation->reference_images)->toBe(['https://example.com/style.png'])
        ->and($generation->est_max_nanos->toDecimal())->toBe('0.373875000')
        ->and($generation->provider_id)->toBeNull()
        ->and($generation->charged_nanos)->toBeNull()
        ->and($generation->user)->toBeInstanceOf(User::class)
        ->and($generation->user->generations->pluck('id')->all())->toBe([$generation->id]);
});

it('has factory states for every status', function (string $state, GenerationStatus $status) {
    $generation = Generation::factory()->{$state}()->create()->fresh();

    expect($generation->status)->toBe($status);
})->with([
    ['pending', GenerationStatus::Pending],
    ['queued', GenerationStatus::Queued],
    ['processing', GenerationStatus::Processing],
    ['succeeded', GenerationStatus::Succeeded],
    ['failed', GenerationStatus::Failed],
]);

it('fills in the outcome of a succeeded generation', function () {
    $generation = Generation::factory()->succeeded()->create()->fresh();

    expect($generation->provider_id)->toStartWith('gen_')
        ->and($generation->usage)->toHaveKeys(['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens', 'total_tokens'])
        ->and($generation->rates)->toHaveKeys(['input_text_tokens', 'input_image_tokens', 'output_tokens', 'reasoning_tokens'])
        ->and($generation->provider_cost_nanos)->toBeInstanceOf(Money::class)
        ->and($generation->charged_nanos->isGreaterThan($generation->provider_cost_nanos))->toBeTrue()
        ->and($generation->image_url)->toStartWith('https://')
        ->and($generation->image_url_expires_at->isFuture())->toBeTrue()
        ->and($generation->completed_at)->not->toBeNull()
        ->and($generation->started_at)->not->toBeNull();
});

it('records the error of a failed generation and charges nothing', function () {
    $generation = Generation::factory()->failed()->create()->fresh();

    expect($generation->error_code)->toBe('generation_failed')
        ->and($generation->error_message)->not->toBeEmpty()
        ->and($generation->charged_nanos)->toEqual(Money::zero())
        ->and($generation->completed_at)->not->toBeNull();
});

it('can mark an image as expired', function () {
    $generation = Generation::factory()->succeeded()->imageExpired()->create()->fresh();

    expect($generation->image_url_expires_at->isPast())->toBeTrue();
});

it('enforces a unique idempotency key', function () {
    Generation::factory()->create(['idempotency_key' => 'same']);
    Generation::factory()->create(['idempotency_key' => 'same']);
})->throws(UniqueConstraintViolationException::class);

it('enforces a unique provider id but allows many unsubmitted generations', function () {
    Generation::factory()->count(2)->create(['provider_id' => null]);
    expect(Generation::count())->toBe(2);

    Generation::factory()->create(['provider_id' => 'gen_same']);
    Generation::factory()->create(['provider_id' => 'gen_same']);
})->throws(UniqueConstraintViolationException::class);
