<?php

use App\Pricing\CostCalculator;
use App\Pricing\TokenUsage;

beforeEach(function () {
    $this->calculator = new CostCalculator(markupBasisPoints: 2500);
    $this->rates = pixelforgePrices()->rates;
});

it('matches the usage example in the Pixelforge docs', function () {
    // 2026-11-01 row: 41 text, 0 image, 3300 output, 2950 reasoning = "0.169080000"
    $usage = new TokenUsage(inputText: 41, inputImage: 0, output: 3300, reasoning: 2950);

    expect($this->calculator->cost($usage, $this->rates))->toBe(169_080_000);
});

it('matches the maximum-cost example in the Pixelforge docs', function () {
    // high, 1536x1024, 400 characters, 2 reference images, maximum text and reasoning
    $usage = new TokenUsage(inputText: 115, inputImage: 1530, output: 6450, reasoning: 8000);

    expect($this->calculator->cost($usage, $this->rates))->toBe(373_875_000);
});

it('prices one token of each kind exactly', function () {
    expect($this->calculator->cost(new TokenUsage(inputText: 1), $this->rates))->toBe(5_000)
        ->and($this->calculator->cost(new TokenUsage(inputImage: 1), $this->rates))->toBe(10_000)
        ->and($this->calculator->cost(new TokenUsage(output: 1), $this->rates))->toBe(40_000)
        ->and($this->calculator->cost(new TokenUsage(reasoning: 1), $this->rates))->toBe(12_500);
});

it('builds usage from a Pixelforge usage object', function () {
    $usage = TokenUsage::fromArray([
        'input_text_tokens' => 41,
        'input_image_tokens' => 0,
        'output_tokens' => 3300,
        'reasoning_tokens' => 2950,
        'total_tokens' => 6291,
    ]);

    expect($this->calculator->cost($usage, $this->rates))->toBe(169_080_000)
        ->and($usage->total())->toBe(6291);
});

it('uses the rates it is given, not the published ones', function () {
    $rates = [...$this->rates, 'output_tokens' => '20.00'];

    expect($this->calculator->cost(new TokenUsage(output: 1000), $rates))->toBe(20_000_000);
});

it('rounds up once if a rate is finer than a nano-dollar per token', function () {
    $rates = [...$this->rates, 'input_text_tokens' => '0.0001']; // 0.1 nano-dollars per token

    expect($this->calculator->cost(new TokenUsage(inputText: 1), $rates))->toBe(1)
        ->and($this->calculator->cost(new TokenUsage(inputText: 10), $rates))->toBe(1)
        ->and($this->calculator->cost(new TokenUsage(inputText: 11), $rates))->toBe(2);
});

it('charges the user cost plus 25%', function () {
    expect($this->calculator->price(169_080_000))->toBe(211_350_000);
});

it('refuses to compute a cost with a missing rate', function () {
    $rates = $this->rates;
    unset($rates['reasoning_tokens']);

    $this->calculator->cost(new TokenUsage(reasoning: 1), $rates);
})->throws(InvalidArgumentException::class);

it('rejects negative token counts', function () {
    new TokenUsage(inputText: -1);
})->throws(InvalidArgumentException::class);
