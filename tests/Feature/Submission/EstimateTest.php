<?php

use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use Tests\Fakes\FakeCostEstimator;
use Tests\Fakes\FakeLedger;

beforeEach(function () {
    $this->estimator = FakeCostEstimator::swap()->returnsUserPrices('0.10', '0.20', '0.30');
    $this->ledger = FakeLedger::swap();
});

it('sends a guest to the login page', function () {
    $this->post(route('estimate'), ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertRedirect(route('login'));
});

it('returns the min, expected and max user price with what the user can spend', function () {
    $user = User::factory()->withBalance('0.25')->create();

    $this->actingAs($user)
        ->postJson(route('estimate'), [
            'prompt' => 'A lighthouse ☕',
            'size' => '1024x1536',
            'quality' => 'low',
            'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
        ])
        ->assertOk()
        ->assertExactJson([
            'estimate' => ['min' => '0.100000000', 'expected' => '0.200000000', 'max' => '0.300000000'],
            'available' => '0.250000000',
            'can_afford' => false,
        ]);

    $input = $this->estimator->inputs[0];
    expect($input->prompt)->toBe('A lighthouse ☕')
        ->and($input->size)->toBe(Size::Portrait)
        ->and($input->quality)->toBe(Quality::Low)
        ->and($input->referenceImageCount)->toBe(2);
});

it('can afford exactly the max', function () {
    $this->actingAs(User::factory()->withBalance('0.30')->create())
        ->postJson(route('estimate'), ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertOk()
        ->assertJsonPath('can_afford', true);
});

it('neither holds nor creates anything', function () {
    $this->actingAs(User::factory()->withBalance('1.00')->create())
        ->postJson(route('estimate'), ['prompt' => 'A cat', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertOk();

    expect(Generation::count())->toBe(0);
    $this->ledger->assertNothingHeld();
});

it('returns 422 JSON field errors with the submit rules', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('estimate'), ['prompt' => str_repeat('a', 4001), 'size' => 'x', 'quality' => 'ultra', 'reference_images' => ['ftp://a']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['prompt', 'size', 'quality', 'reference_images.0']);
});
