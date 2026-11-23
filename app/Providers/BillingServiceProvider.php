<?php

namespace App\Providers;

use App\Billing\DatabaseLedger;
use App\Contracts\Ledger;
use Illuminate\Support\ServiceProvider;

/** Session 3's wiring: the real Ledger. */
class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Ledger::class, DatabaseLedger::class);
    }
}
