<?php

namespace App\Pricing;

use App\Contracts\Data\Rates;
use App\Contracts\Data\TokenModel;
use App\Contracts\PricingCatalog;
use App\Pixelforge\Data\ProviderPricing;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;

/**
 * Today's Pixelforge pricing from GET /v1/pricing, cached for pixelforge.pricing.cache_ttl seconds.
 * When the endpoint fails (or answers with something that isn't pricing) we use the documented
 * pricing in config('pixelforge.pricing') and try the endpoint again after RETRY_AFTER_FAILURE seconds.
 */
final readonly class CachedPricingCatalog implements PricingCatalog
{
    public const string CACHE_KEY = 'pixelforge:pricing';

    public const string UNAVAILABLE_KEY = 'pixelforge:pricing:unavailable';

    public const int RETRY_AFTER_FAILURE = 60;

    public function __construct(
        private PixelforgeClient $client,
        private Cache $cache,
        private Config $config,
        private LoggerInterface $logger,
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
        if ($this->cache->has(self::UNAVAILABLE_KEY)) {
            return $this->fallback();
        }

        try {
            /** @var array<string, mixed> $raw */
            $raw = $this->cache->remember(
                self::CACHE_KEY,
                (int) $this->config->get('pixelforge.pricing.cache_ttl', 3600),
                fn (): array => $this->client->getPricing()->raw,
            );

            return ProviderPricing::fromArray($raw);
        } catch (PixelforgeException|InvalidArgumentException $e) {
            $this->logger->warning('Pixelforge pricing is unavailable; using the configured pricing.', [
                'exception' => $e,
            ]);
            $this->cache->put(self::UNAVAILABLE_KEY, true, self::RETRY_AFTER_FAILURE);

            return $this->fallback();
        }
    }

    private function fallback(): ProviderPricing
    {
        /** @var array<string, mixed> $pricing */
        $pricing = $this->config->get('pixelforge.pricing', []);

        return ProviderPricing::fromArray($pricing);
    }
}
