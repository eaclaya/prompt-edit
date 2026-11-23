<?php

use App\Providers\AdminServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\BillingServiceProvider;
use App\Providers\ContractsServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\GenerationsServiceProvider;
use App\Providers\PricingServiceProvider;

return [
    AppServiceProvider::class,
    ContractsServiceProvider::class,
    FortifyServiceProvider::class,
    AdminServiceProvider::class,
    BillingServiceProvider::class,
    GenerationsServiceProvider::class,
    PricingServiceProvider::class,
];
