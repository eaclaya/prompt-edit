<?php

namespace App\Providers;

use App\Contracts\GenerationSync;
use App\Generations\SyncGeneration;
use Illuminate\Support\ServiceProvider;

/** S5: webhooks and state sync. */
class GenerationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GenerationSync::class, SyncGeneration::class);
    }
}
