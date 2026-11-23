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
