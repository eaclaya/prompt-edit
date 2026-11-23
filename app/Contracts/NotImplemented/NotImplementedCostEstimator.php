<?php

namespace App\Contracts\NotImplemented;

use App\Contracts\CostEstimator;
use App\Contracts\Data\Estimate;
use App\Contracts\Data\EstimateInput;

final class NotImplementedCostEstimator implements CostEstimator
{
    public function estimate(EstimateInput $input): Estimate
    {
        throw NotImplementedException::for(CostEstimator::class, 'S2');
    }
}
