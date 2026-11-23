<?php

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeCostEstimator;
use Tests\Fakes\FakeLedger;

beforeEach(function () {
    Queue::fake();
    $this->estimator = FakeCostEstimator::swap()->returnsUserPrices('0.10', '0.20', '0.30');
    $this->ledger = FakeLedger::swap();
});

function submission(array $overrides = []): array
{
    return array_replace([
        'prompt' => 'A lighthouse at dusk, oil painting',
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/ref.png'],
    ], $overrides);
}

it('rejects a submit the user cannot afford and creates nothing', function () {
    $user = User::factory()->withBalance('0.29')->create();

    $this->actingAs($user)
        ->from(route('generations.create'))
        ->post(route('generations.store'), submission())
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors(['credits' => 'Insufficient credits: $0.3000 required, $0.2900 available.']);

    expect(Generation::count())->toBe(0);
    $this->ledger->assertNothingHeld();
    Queue::assertNothingPushed();
});

it('counts open holds against what the user can afford', function () {
    $user = User::factory()->withBalance('0.50')->create();

    $this->actingAs($user)->post(route('generations.store'), submission())->assertSessionHasNoErrors();
    $this->actingAs($user)->post(route('generations.store'), submission())->assertSessionHasErrors('credits');

    expect(Generation::count())->toBe(1);
    Queue::assertPushed(SubmitGeneration::class, 1);
});

it('creates a pending generation, holds the max user price, dispatches the submission and redirects to history', function () {
    $user = User::factory()->withBalance('0.30')->create();

    $this->actingAs($user)
        ->post(route('generations.store'), submission())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('generations.index'));

    $generation = Generation::sole();
    expect($generation->user_id)->toBe($user->id)
        ->and($generation->status)->toBe(GenerationStatus::Pending)
        ->and($generation->prompt)->toBe('A lighthouse at dusk, oil painting')
        ->and($generation->size)->toBe(Size::Landscape)
        ->and($generation->quality)->toBe(Quality::High)
        ->and($generation->reference_images)->toBe(['https://example.com/ref.png'])
        ->and($generation->est_min_nanos)->toEqualMoney('0.10')
        ->and($generation->est_expected_nanos)->toEqualMoney('0.20')
        ->and($generation->est_max_nanos)->toEqualMoney('0.30')
        ->and($generation->idempotency_key)->toBe($generation->id)
        ->and($generation->provider_id)->toBeNull();

    $this->ledger->assertHeld($generation, '0.30');
    expect($this->ledger->available($user))->toEqualMoney(Money::zero());
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
});

it('estimates the submit from the validated input', function () {
    $this->actingAs(User::factory()->withBalance('1.00')->create())
        ->post(route('generations.store'), submission(['prompt' => 'Café ☕', 'reference_images' => []]));

    $input = $this->estimator->inputs[0];
    expect($input->prompt)->toBe('Café ☕')
        ->and($input->size)->toBe(Size::Landscape)
        ->and($input->quality)->toBe(Quality::High)
        ->and($input->referenceImageCount)->toBe(0);
});

it('treats missing reference images as none', function () {
    $this->actingAs(User::factory()->withBalance('1.00')->create())
        ->post(route('generations.store'), array_diff_key(submission(), ['reference_images' => true]))
        ->assertSessionHasNoErrors();

    expect(Generation::sole()->reference_images)->toBe([]);
});
