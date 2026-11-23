<?php

namespace App\Providers;

use App\Contracts\CostCalculator;
use App\Contracts\CostEstimator;
use App\Contracts\Markup;
use App\Contracts\PricingCatalog;
use App\Pricing\BasisPointsMarkup;
use App\Pricing\CachedPricingCatalog;
use App\Pricing\TokenCostCalculator;
use App\Pricing\TokenCostEstimator;
use Illuminate\Support\ServiceProvider;

/** Session 2: the pricing contracts. Plain bind(), so config changes (and test fakes) are picked up. */
class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CostCalculator::class, TokenCostCalculator::class);
        $this->app->bind(Markup::class, fn () => new BasisPointsMarkup((int) config('pixelforge.markup_bps')));
        $this->app->bind(PricingCatalog::class, CachedPricingCatalog::class);
        $this->app->bind(CostEstimator::class, TokenCostEstimator::class);
    }
}
