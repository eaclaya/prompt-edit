<?php

namespace App\Contracts\NotImplemented;

use App\Contracts\CostCalculator;
use App\Contracts\Data\Rates;
use App\Contracts\Data\Usage;
use App\Support\Money;

final class NotImplementedCostCalculator implements CostCalculator
{
    public function providerCost(Usage $usage, Rates $rates): Money
    {
        throw NotImplementedException::for(CostCalculator::class, 'S2');
    }
}
