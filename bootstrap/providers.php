<?php

use App\Providers\AppServiceProvider;
use App\Providers\ContractsServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\GenerationsServiceProvider;

return [
    AppServiceProvider::class,
    ContractsServiceProvider::class,
    FortifyServiceProvider::class,
    GenerationsServiceProvider::class,
];
