<?php

use App\Contracts\Data\Rates;
use App\Contracts\Data\Usage;
use App\Pricing\TokenCostCalculator;

function docsRates(): Rates
{
    return Rates::fromArray(config('pixelforge.pricing.rates'));
}

it('prices the docs example usage (12 + 765 + 6450 + 3184 tokens) exactly', function () {
    $usage = Usage::fromArray([
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
        'reasoning_tokens' => 3184,
        'total_tokens' => 10411,
    ]);

    // 12×5 + 765×10 + 6450×40 + 3184×12.50 = 305,510 µ$ per 1M tokens
    expect((new TokenCostCalculator)->providerCost($usage, docsRates()))->toEqualMoney('0.305510');
});

it('rounds a fraction of a nanodollar up, once, on the total', function () {
    // 1 token at $0.000000001 / 1M = 0.000001 nanodollars, twice over: still 1 nanodollar, not 2.
    $rates = Rates::fromArray([
        'input_text_tokens' => '0.000000001',
        'input_image_tokens' => '0.000000001',
        'output_tokens' => '0',
        'reasoning_tokens' => '0',
    ]);

    expect((new TokenCostCalculator)->providerCost(new Usage(1, 1, 0, 0), $rates))->toEqualMoney(1);
});

it('costs nothing for zero usage', function () {
    expect((new TokenCostCalculator)->providerCost(new Usage(0, 0, 0, 0), docsRates()))->toEqualMoney('0');
});

it('fails loudly when usage is missing a token type', function () {
    (new TokenCostCalculator)->providerCost(Usage::fromArray([
        'input_text_tokens' => 12,
        'input_image_tokens' => 765,
        'output_tokens' => 6450,
    ]), docsRates());
})->throws(InvalidArgumentException::class, 'reasoning_tokens');

it('fails loudly when rates are missing a token type', function () {
    (new TokenCostCalculator)->providerCost(new Usage(12, 765, 6450, 3184), Rates::fromArray([
        'input_text_tokens' => '5.00',
        'input_image_tokens' => '10.00',
        'output_tokens' => '40.00',
    ]));
})->throws(InvalidArgumentException::class, 'reasoning_tokens');

it('fails loudly on a negative token count', function () {
    Usage::fromArray([
        'input_text_tokens' => -1,
        'input_image_tokens' => 0,
        'output_tokens' => 0,
        'reasoning_tokens' => 0,
    ]);
})->throws(InvalidArgumentException::class, 'input_text_tokens');

it('handles amounts beyond 64-bit intermediate products', function () {
    // 10M output tokens at $1,000 / 1M tokens: 1e7 × 1e12 nanos overflows int64 before the division.
    $rates = Rates::fromArray([
        'input_text_tokens' => '0',
        'input_image_tokens' => '0',
        'output_tokens' => '1000',
        'reasoning_tokens' => '0',
    ]);

    expect((new TokenCostCalculator)->providerCost(new Usage(0, 0, 10_000_000, 0), $rates))->toEqualMoney('10000');
});
