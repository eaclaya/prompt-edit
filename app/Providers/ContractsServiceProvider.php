<?php

namespace App\Providers;

use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\ServiceProvider;

/**
 * Session 1's shared wiring: the Pixelforge client, and every contract in
 * app/Contracts bound to a NotImplemented stub until its owner binds it.
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
