<?php

use App\Providers\AppServiceProvider;
use App\Providers\ContractsServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\PricingServiceProvider;

return [
    AppServiceProvider::class,
    ContractsServiceProvider::class,
    FortifyServiceProvider::class,
    PricingServiceProvider::class,
];
