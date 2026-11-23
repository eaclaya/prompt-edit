<?php

namespace App\Contracts\NotImplemented;

use App\Contracts\Data\Rates;
use App\Contracts\Data\TokenModel;
use App\Contracts\PricingCatalog;

final class NotImplementedPricingCatalog implements PricingCatalog
{
    public function current(): Rates
    {
        throw NotImplementedException::for(PricingCatalog::class, 'S2');
    }

    public function tokenModel(): TokenModel
    {
        throw NotImplementedException::for(PricingCatalog::class, 'S2');
    }
}
