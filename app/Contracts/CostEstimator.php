<?php

namespace App\Contracts;

use App\Contracts\Data\Estimate;
use App\Contracts\Data\EstimateInput;

/** Implemented by S2 (app/Pricing). */
interface CostEstimator
{
    /**
     * min / expected / max provider cost and user price for a request, using the
     * current rates and token model. min ≤ expected ≤ max; any real usage costs within [min, max].
     */
    public function estimate(EstimateInput $input): Estimate;
}
