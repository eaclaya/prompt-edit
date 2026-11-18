<?php

namespace App\Pricing;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Supplies the current Pixelforge price list for estimates.
 *
 * GET /v1/pricing needs no API key. It is cached for an hour so estimates
 * stay fast, and if it can't be fetched the published prices in config are
 * used, so estimating never fails.
 */
final readonly class PricingCatalog
{
    private const CACHE_KEY = 'pixelforge:pricing';

    private const CACHE_SECONDS = 3600;

    public function __construct(private Cache $cache) {}

    public function current(): PriceList
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return PriceList::fromArray($cached);
        }

        try {
            $data = Http::baseUrl(config('pixelforge.base_url'))
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->get('/pricing')
                ->throw()
                ->json();

            $prices = PriceList::fromArray($data);
            $this->cache->put(self::CACHE_KEY, $data, self::CACHE_SECONDS);

            return $prices;
        } catch (Throwable $e) {
            Log::warning('Pixelforge pricing unavailable, using configured fallback.', [
                'error' => $e->getMessage(),
            ]);

            return self::fallback();
        }
    }

    public static function fallback(): PriceList
    {
        return PriceList::fromArray(config('pixelforge.fallback_pricing'));
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }
}
