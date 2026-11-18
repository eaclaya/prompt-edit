<?php

use App\Pricing\PricingCatalog;
use Illuminate\Support\Facades\Http;

it('fetches the price list from Pixelforge', function () {
    $response = pixelforgePricingResponse();
    $response['rates']['output_tokens'] = '42.00';
    Http::fake(['*/v1/pricing' => Http::response($response)]);

    $prices = app(PricingCatalog::class)->current();

    expect($prices->rates['output_tokens'])->toBe('42.00');
    Http::assertSentCount(1);
});

it('caches the price list', function () {
    Http::fake(['*/v1/pricing' => Http::response(pixelforgePricingResponse())]);
    $catalog = app(PricingCatalog::class);

    $catalog->current();
    $catalog->current();

    Http::assertSentCount(1);
});

it('falls back to the published prices when Pixelforge is unavailable', function () {
    Http::fake(['*/v1/pricing' => Http::response('', 503)]);

    $prices = app(PricingCatalog::class)->current();

    expect($prices)->toEqual(pixelforgePrices());
});

it('falls back when the response is malformed', function () {
    Http::fake(['*/v1/pricing' => Http::response(['rates' => []])]);

    expect(app(PricingCatalog::class)->current())->toEqual(pixelforgePrices());
});

it('keeps the configured fallback in sync with the published prices', function () {
    expect(PricingCatalog::fallback())->toEqual(pixelforgePrices());
});
