<?php

namespace App\Providers;

use App\Contracts;
use App\Contracts\NotImplemented;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Support\ServiceProvider;

/**
 * Session 1's shared wiring: the Pixelforge client, and every contract in
 * app/Contracts bound to a NotImplemented stub until its owner binds it.
 */
class ContractsServiceProvider extends ServiceProvider
{
    /**
     * Contract => stub. bindIf() lets any other provider's bind() win,
     * whichever order the providers are registered in.
     *
     * @var array<class-string, class-string>
     */
    public const array STUBS = [
        Contracts\CostCalculator::class => NotImplemented\NotImplementedCostCalculator::class,
        Contracts\Markup::class => NotImplemented\NotImplementedMarkup::class,
        Contracts\CostEstimator::class => NotImplemented\NotImplementedCostEstimator::class,
        Contracts\PricingCatalog::class => NotImplemented\NotImplementedPricingCatalog::class,
        Contracts\Ledger::class => NotImplemented\NotImplementedLedger::class,
        Contracts\GenerationSync::class => NotImplemented\NotImplementedGenerationSync::class,
    ];

    public function register(): void
    {
        foreach (self::STUBS as $contract => $stub) {
            $this->app->bindIf($contract, $stub);
        }

        $this->app->bind(PixelforgeClient::class, fn () => new PixelforgeClient(
            baseUrl: (string) config('pixelforge.base_url'),
            apiKey: config('pixelforge.api_key'),
            timeout: (int) config('pixelforge.timeout'),
            connectTimeout: (int) config('pixelforge.connect_timeout'),
        ));
    }
}
