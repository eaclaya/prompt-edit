<?php

use App\Enums\Quality;
use App\Enums\Size;
use App\Pricing\CachedPricingCatalog;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

it('falls back to config when the endpoint fails', function (string $failure) {
    $fake = PixelforgeFake::fake();
    $failure === 'timeout' ? $fake->failsToConnect('pricing') : $fake->failsWith($failure, endpoint: 'pricing');

    expect(catalog()->current()->toArray())->toBe([
        'input_text_tokens' => '5.000000000',
        'input_image_tokens' => '10.000000000',
        'output_tokens' => '40.000000000',
        'reasoning_tokens' => '12.500000000',
    ])
        ->and(catalog()->tokenModel()->outputTokens(Quality::High, Size::Landscape))->toBe(6450);
})->with(['overloaded', 'not_found', 'timeout']);

it('falls back to config when the response is not valid pricing', function () {
    Http::fake(['*/pricing' => Http::response(['object' => 'pricing', 'rates' => ['output_tokens' => 40]])]);

    expect(catalog()->current()->output)->toEqualMoney('40.00');
});

it('uses the configured fallback rates', function () {
    config(['pixelforge.pricing.rates.output_tokens' => '45.00']);
    PixelforgeFake::fake()->failsToConnect('pricing');

    expect(catalog()->current()->output)->toEqualMoney('45.00');
});

it('retries the endpoint a minute after a failure instead of caching the fallback for an hour', function () {
    PixelforgeFake::fake()
        ->failsWith('overloaded', endpoint: 'pricing')
        ->returnsPricing(['rates' => ['output_tokens' => '50.00']]);

    expect(catalog()->current()->output)->toEqualMoney('40.00');

    catalog()->current();
    expect(pricingRequests())->toBe(1);

    $this->travel(61)->seconds();

    expect(catalog()->current()->output)->toEqualMoney('50.00')
        ->and(pricingRequests())->toBe(2);
});

it('logs a warning when it falls back', function () {
    Log::spy();
    PixelforgeFake::fake()->failsToConnect('pricing');

    catalog()->current();

    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message) => str_contains($message, 'pricing is unavailable'));
});
