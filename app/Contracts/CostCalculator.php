<?php

namespace App\Contracts;

use App\Contracts\Data\Rates;
use App\Contracts\Data\Usage;
use App\Support\Money;

/** Implemented by S2 (app/Pricing). */
interface CostCalculator
{
    /**
     * What Pixelforge bills: Σ tokens × rate / 1M, exact to the nanodollar.
     * Uses the rates fixed on the generation, not today's.
     */
    public function providerCost(Usage $usage, Rates $rates): Money;
}
