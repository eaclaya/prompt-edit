<?php

namespace App\Pricing;

use App\Contracts\Data\Rates;
use App\Contracts\Data\TokenModel;
use App\Contracts\PricingCatalog;
use App\Pixelforge\Data\ProviderPricing;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;

/** Today's Pixelforge pricing from GET /v1/pricing, cached for pixelforge.pricing.cache_ttl seconds. */
final readonly class CachedPricingCatalog implements PricingCatalog
{
    public const string CACHE_KEY = 'pixelforge:pricing';

    public function __construct(
        private PixelforgeClient $client,
        private Cache $cache,
        private Config $config,
    ) {}

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
        /** @var array<string, mixed> $raw */
        $raw = $this->cache->remember(
            self::CACHE_KEY,
            (int) $this->config->get('pixelforge.pricing.cache_ttl', 3600),
            fn (): array => $this->client->getPricing()->raw,
        );

        return ProviderPricing::fromArray($raw);
    }
}
