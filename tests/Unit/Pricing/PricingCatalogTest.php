<?php

use App\Enums\Quality;
use App\Enums\Size;
use App\Pricing\CachedPricingCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fakes\PixelforgeFake;

function catalog(): CachedPricingCatalog
{
    return app(CachedPricingCatalog::class);
}

function pricingRequests(): int
{
    return Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/pricing'))->count();
}

it('reads the rates and token model from GET /v1/pricing', function () {
    PixelforgeFake::fake()->returnsPricing([
        'rates' => ['output_tokens' => '50.00'],
        'tokens' => ['output' => ['high' => ['1536x1024' => 7000]]],
    ]);

    expect(catalog()->current()->output)->toEqualMoney('50.00')
        ->and(catalog()->current()->inputText)->toEqualMoney('5.00')
        ->and(catalog()->tokenModel()->outputTokens(Quality::High, Size::Landscape))->toBe(7000)
        ->and(catalog()->tokenModel()->maxReasoningTokens(Quality::High))->toBe(8000);
});

it('caches the pricing for an hour', function () {
    PixelforgeFake::fake()
        ->returnsPricing(['rates' => ['output_tokens' => '50.00']])
        ->returnsPricing(['rates' => ['output_tokens' => '60.00']]);

    catalog()->current();
    catalog()->tokenModel();
    $this->travel(59)->minutes();

    expect(catalog()->current()->output)->toEqualMoney('50.00')
        ->and(pricingRequests())->toBe(1);

    $this->travel(2)->minutes();

    expect(catalog()->current()->output)->toEqualMoney('60.00')
        ->and(pricingRequests())->toBe(2);
});

it('takes the cache lifetime from config', function () {
    config(['pixelforge.pricing.cache_ttl' => 60]);
    PixelforgeFake::fake()->returnsPricing();

    catalog()->current();
    $this->travel(61)->seconds();
    catalog()->current();

    expect(pricingRequests())->toBe(2);
});
