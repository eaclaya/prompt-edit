<?php

use App\Contracts\CostCalculator;
use App\Contracts\CostEstimator;
use App\Contracts\Data\EstimateInput;
use App\Contracts\Markup;
use App\Contracts\PricingCatalog;
use App\Enums\Quality;
use App\Enums\Size;
use App\Pricing\BasisPointsMarkup;
use App\Pricing\CachedPricingCatalog;
use App\Pricing\TokenCostCalculator;
use App\Pricing\TokenCostEstimator;
use App\Support\Money;
use Tests\Fakes\PixelforgeFake;

it('binds every pricing contract to its real implementation', function (string $contract, string $implementation) {
    expect(app($contract))->toBeInstanceOf($implementation);
})->with([
    [CostCalculator::class, TokenCostCalculator::class],
    [Markup::class, BasisPointsMarkup::class],
    [CostEstimator::class, TokenCostEstimator::class],
    [PricingCatalog::class, CachedPricingCatalog::class],
]);

it('reads the markup from config', function () {
    config(['pixelforge.markup_bps' => 1000]);

    expect(app(Markup::class)->apply(Money::of('1.00')))->toEqualMoney('1.10');
});

it('estimates the docs worked example end to end through the container', function () {
    PixelforgeFake::fake()->returnsPricing();

    $estimate = app(CostEstimator::class)->estimate(new EstimateInput(str_repeat('a', 400), Size::Landscape, Quality::High, 2));

    expect($estimate->toArray())->toBe([
        'min' => '0.357781250',
        'expected' => '0.412562500',
        'max' => '0.467343750',
    ]);
});
