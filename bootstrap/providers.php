<?php

use App\Providers\AdminServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\ContractsServiceProvider;
use App\Providers\FortifyServiceProvider;

return [
    AppServiceProvider::class,
    ContractsServiceProvider::class,
    FortifyServiceProvider::class,
    AdminServiceProvider::class,
];
