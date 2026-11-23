<?php

namespace App\Providers;

use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\ServiceProvider;

/**
 * The Pixelforge HTTP client. Each contract in app/Contracts is bound by the
 * provider of the session that implements it (Pricing, Billing, Generations).
 */
class ContractsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: (string) config('pixelforge.base_url'),
            apiKey: config('pixelforge.api_key'),
            timeout: (int) config('pixelforge.timeout'),
            connectTimeout: (int) config('pixelforge.connect_timeout'),
        ));
    }
}
