<?php

use App\Contracts\CostCalculator;
use App\Contracts\CostEstimator;
use App\Contracts\Data\EstimateInput;
use App\Contracts\Data\Rates;
use App\Contracts\Data\Usage;
use App\Contracts\Exceptions\InsufficientCredits;
use App\Contracts\GenerationSync;
use App\Contracts\Ledger;
use App\Contracts\Markup;
use App\Contracts\PricingCatalog;
use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Pixelforge\Data\ProviderGeneration;
use App\Support\Money;
use Tests\Fakes\FakeCostCalculator;
use Tests\Fakes\FakeCostEstimator;
use Tests\Fakes\FakeGenerationSync;
use Tests\Fakes\FakeLedger;
use Tests\Fakes\FakeMarkup;
use Tests\Fakes\FakePricingCatalog;
use Tests\Fixtures\PixelforgeFixtures;

it('swaps each fake into the container', function (string $contract, string $fake) {
    $instance = $fake::swap();

    expect(app($contract))->toBe($instance)->toBeInstanceOf($contract);
})->with([
    [CostCalculator::class, FakeCostCalculator::class],
    [Markup::class, FakeMarkup::class],
    [CostEstimator::class, FakeCostEstimator::class],
    [PricingCatalog::class, FakePricingCatalog::class],
    [Ledger::class, FakeLedger::class],
    [GenerationSync::class, FakeGenerationSync::class],
]);

describe('FakeCostCalculator', function () {
    it('computes the docs example cost from usage and rates', function () {
        $cost = FakeCostCalculator::swap()->providerCost(new Usage(12, 765, 6450, 3184), Rates::fromArray(config('pixelforge.pricing.rates')));

        expect($cost)->toEqualMoney('0.305510');
    });

    it('can return a fixed cost and records its calls', function () {
        $fake = FakeCostCalculator::swap()->returns(Money::of('0.1'));

        expect($fake->providerCost(new Usage(1, 0, 0, 0), Rates::fromArray(config('pixelforge.pricing.rates'))))->toEqualMoney('0.1')
            ->and($fake->calls)->toHaveCount(1);
    });
});

describe('FakeMarkup', function () {
    it('adds 25% rounded up, or a fixed amount', function () {
        $fake = FakeMarkup::swap();

        expect($fake->apply(Money::ofNanos(1)))->toEqualMoney(2)
            ->and($fake->apply(Money::of('0.305510')))->toEqualMoney('0.3818875')
            ->and($fake->returns(Money::of('9'))->apply(Money::of('1')))->toEqualMoney('9');
    });
});

describe('FakeCostEstimator', function () {
    it('returns the docs worked example by default and records inputs', function () {
        $fake = FakeCostEstimator::swap();
        $input = new EstimateInput(str_repeat('a', 400), Size::Landscape, Quality::High, 2);

        $estimate = $fake->estimate($input);

        expect($estimate->providerMax)->toEqualMoney('0.373875')
            ->and($estimate->userMax)->toEqualMoney('0.46734375')
            ->and($estimate->userMin->isLessThanOrEqualTo($estimate->userExpected))->toBeTrue()
            ->and($estimate->userExpected->isLessThanOrEqualTo($estimate->userMax))->toBeTrue()
            ->and($fake->inputs)->toBe([$input]);
    });

    it('can return given user prices', function () {
        $estimate = FakeCostEstimator::swap()->returnsUserPrices('0.10', '0.20', '0.30')
            ->estimate(new EstimateInput('x', Size::Square, Quality::Low));

        expect($estimate->toArray())->toBe(['min' => '0.100000000', 'expected' => '0.200000000', 'max' => '0.300000000']);
    });
});

describe('FakePricingCatalog', function () {
    it('serves the documented pricing, or overridden rates', function () {
        $fake = FakePricingCatalog::swap();

        expect($fake->current()->output)->toEqualMoney('40.00')
            ->and($fake->tokenModel()->maxReasoningTokens(Quality::High))->toBe(8000)
            ->and($fake->withRates(['output_tokens' => '50.00'])->current()->output)->toEqualMoney('50.00')
            ->and($fake->current()->inputText)->toEqualMoney('5.00');
    });
});

describe('FakeLedger', function () {
    it('starts from the cached balance and grants the welcome credit once', function () {
        $ledger = FakeLedger::swap();
        $user = User::factory()->withBalance('0.50')->create();

        $ledger->grantWelcome($user);
        $ledger->grantWelcome($user);

        expect($ledger->balance($user))->toEqualMoney('1.50')
            ->and($ledger->available($user))->toEqualMoney('1.50');
    });

    it('credits a purchase token once', function () {
        $ledger = FakeLedger::swap();
        $user = User::factory()->create();

        $ledger->purchase($user, Money::of('5'), 'tok');
        $ledger->purchase($user, Money::of('5'), 'tok');

        expect($ledger->balance($user))->toEqualMoney('5');
    });

    it('holds, then settles the actual charge once', function () {
        $ledger = FakeLedger::swap();
        $user = User::factory()->withBalance('1.00')->create();
        $generation = Generation::factory()->for($user)->create();

        $ledger->hold($generation, Money::of('0.40'));
        expect($ledger->available($user))->toEqualMoney('0.60')
            ->and($ledger->balance($user))->toEqualMoney('1.00');
        $ledger->assertHeld($generation, '0.40');

        $ledger->settle($generation, Money::of('0.30'));
        $ledger->settle($generation, Money::of('0.30'));

        expect($ledger->balance($user))->toEqualMoney('0.70')
            ->and($ledger->available($user))->toEqualMoney('0.70');
        $ledger->assertSettled($generation, '0.30');
        $ledger->assertNotReleased($generation);
    });

    it('releases a hold and charges nothing', function () {
        $ledger = FakeLedger::swap();
        $user = User::factory()->withBalance('1.00')->create();
        $generation = Generation::factory()->for($user)->create();

        $ledger->hold($generation, Money::of('0.40'));
        $ledger->release($generation);

        expect($ledger->available($user))->toEqualMoney('1.00');
        $ledger->assertReleased($generation);
        $ledger->assertNotSettled($generation);
    });

    it('refuses a hold the user cannot afford', function () {
        $ledger = FakeLedger::swap();
        $generation = Generation::factory()->for(User::factory()->withBalance('0.10'))->create();

        expect(fn () => $ledger->hold($generation, Money::of('0.40')))->toThrow(InsufficientCredits::class);
        $ledger->assertNothingHeld();
    });

    it('can be told the user is broke', function () {
        $ledger = FakeLedger::swap();
        $user = User::factory()->withBalance('100')->create();

        $ledger->setBalance($user, Money::zero());

        expect($ledger->available($user))->toEqualMoney('0');
    });
});

describe('FakeGenerationSync', function () {
    it('records what it was given and returns the matching generation without changing it', function () {
        $sync = FakeGenerationSync::swap();
        $local = Generation::factory()->create();
        $provider = ProviderGeneration::fromArray(PixelforgeFixtures::load('generation.queued', ['metadata' => ['local_id' => $local->id]]));

        expect($sync->apply($provider)?->is($local))->toBeTrue()
            ->and($local->fresh()->status)->toBe(GenerationStatus::Pending);
        $sync->assertApplied(fn (ProviderGeneration $g) => $g->localId() === $local->id);
        $sync->assertAppliedTimes(1);
    });

    it('can write through a naive forward-only update for tests that read the row back', function () {
        $sync = FakeGenerationSync::swap()->writeThrough();
        $local = Generation::factory()->create();

        $sync->apply(ProviderGeneration::fromArray(PixelforgeFixtures::load('generation.succeeded', ['metadata' => ['local_id' => $local->id]])));
        $sync->apply(ProviderGeneration::fromArray(PixelforgeFixtures::load('generation.processing', ['metadata' => ['local_id' => $local->id]])));

        $local->refresh();
        expect($local->status)->toBe(GenerationStatus::Succeeded)
            ->and($local->provider_id)->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
            ->and($local->rates)->toEqual(config('pixelforge.pricing.rates')) // jsonb does not keep key order
            ->and($local->usage['total_tokens'])->toBe(10411)
            ->and($local->completed_at)->not->toBeNull();
    });

    it('returns null for an unknown generation', function () {
        expect(FakeGenerationSync::swap()->apply(ProviderGeneration::fromArray(PixelforgeFixtures::load('generation.queued'))))->toBeNull();
    });
});

it('checks a user balance with toHaveBalance', function () {
    $user = User::factory()->withBalance('1.00')->create();

    expect($user)->toHaveBalance('1.00')
        ->and($user)->not->toHaveBalance('0.99');
});
