<?php

namespace App\Pricing;

use App\Contracts\Data\Rates;
use App\Contracts\Data\TokenModel;
use App\Contracts\PricingCatalog;
use App\Pixelforge\Data\ProviderPricing;
use App\Pixelforge\PixelforgeClient;

/** Today's Pixelforge pricing from GET /v1/pricing. */
final readonly class CachedPricingCatalog implements PricingCatalog
{
    public function __construct(private PixelforgeClient $client) {}

    public function current(): Rates
    {
        return $this->pricing()->rates;
    }

    public function tokenModel(): TokenModel
    {
        return $this->pricing()->tokens;
    }

    private function pricing(): ProviderPricing
    {
        return $this->client->getPricing();
    }
}
