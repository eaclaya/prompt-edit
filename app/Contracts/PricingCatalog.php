<?php

namespace App\Contracts;

use App\Contracts\Data\Rates;
use App\Contracts\Data\TokenModel;

/** Implemented by S2 (app/Pricing): GET /v1/pricing cached for 1 h, config('pixelforge.pricing') as fallback. */
interface PricingCatalog
{
    public function current(): Rates;

    public function tokenModel(): TokenModel;
}
