<?php

use App\Enums\Quality;
use App\Enums\Size;
use App\Pricing\CostCalculator;
use App\Pricing\CostEstimator;
use App\Pricing\TokenUsage;

beforeEach(function () {
    $this->calculator = new CostCalculator(markupBasisPoints: 2500);
    $this->estimator = new CostEstimator($this->calculator);
    $this->prices = pixelforgePrices();
});

it('estimates the default generation as a range', function () {
    $estimate = $this->estimator->estimate(str_repeat('a', 100), Quality::Medium, Size::Square, 0, $this->prices);

    expect($estimate->minTokens)->toEqual(new TokenUsage(21, 0, 1100, 200))
        ->and($estimate->maxTokens)->toEqual(new TokenUsage(29, 0, 1100, 2000))
        ->and($estimate->costMinNano)->toBe(46_605_000)
        ->and($estimate->costMaxNano)->toBe(69_145_000)
        ->and($estimate->priceMinNano)->toBe(58_256_250)
        ->and($estimate->priceMaxNano)->toBe(86_431_250);
});

it('reproduces the maximum-cost example from the docs', function () {
    $estimate = $this->estimator->estimate(str_repeat('a', 400), Quality::High, Size::Landscape, 2, $this->prices);

    expect($estimate->maxTokens)->toEqual(new TokenUsage(115, 1530, 6450, 8000))
        ->and($estimate->costMaxNano)->toBe(373_875_000)
        ->and($estimate->priceMaxNano)->toBe(467_343_750);
});

it('computes the text token range with exact arithmetic', function (int $characters, array $range) {
    expect($this->estimator->textTokenRange(str_repeat('a', $characters), $this->prices))->toBe($range);
})->with([
    'empty' => [0, [0, 0]],
    'one character' => [1, [0, 2]],      // estimate 1 -> floor(0.85), ceil(1.15)
    'four characters' => [4, [0, 2]],
    'five characters' => [5, [1, 3]],    // estimate 2
    '100 characters' => [100, [21, 29]], // estimate 25
    '400 characters' => [400, [85, 115]], // estimate 100; 100 * 0.85 must not floor to 84
    '4000 characters' => [4000, [850, 1150]],
]);

it('counts Unicode characters, not bytes', function () {
    // 8 characters, 16 bytes in UTF-8 -> estimate 2 tokens, not 4
    expect($this->estimator->textTokenRange('éééééééé', $this->prices))->toBe([1, 3])
        ->and($this->estimator->textTokenRange('🎨🎨🎨🎨', $this->prices))->toBe([0, 2]);
});

it('counts test keywords as prompt text', function () {
    $plain = $this->estimator->textTokenRange(str_repeat('a', 20), $this->prices);
    $withKeyword = $this->estimator->textTokenRange(str_repeat('a', 20).' [fail]', $this->prices);

    expect($withKeyword[1])->toBeGreaterThan($plain[1]);
});

it('adds 765 exact tokens per reference image', function (int $references) {
    $estimate = $this->estimator->estimate('a cat', Quality::Low, Size::Square, $references, $this->prices);

    expect($estimate->minTokens->inputImage)->toBe(765 * $references)
        ->and($estimate->maxTokens->inputImage)->toBe(765 * $references);
})->with([0, 1, 4]);

it('bounds any actual cost within the estimate for every size and quality', function (Quality $quality, Size $size) {
    $prompt = str_repeat('word ', 37); // 185 characters
    $references = random_int(0, 4);
    $estimate = $this->estimator->estimate($prompt, $quality, $size, $references, $this->prices);

    expect($estimate->costMinNano)->toBeLessThanOrEqual($estimate->costMaxNano)
        ->and($estimate->priceMinNano)->toBeLessThanOrEqual($estimate->priceMaxNano);

    // Any actual usage the provider may report must land inside the range.
    for ($i = 0; $i < 25; $i++) {
        $actual = new TokenUsage(
            inputText: random_int($estimate->minTokens->inputText, $estimate->maxTokens->inputText),
            inputImage: $estimate->minTokens->inputImage,
            output: $estimate->minTokens->output,
            reasoning: random_int($estimate->minTokens->reasoning, $estimate->maxTokens->reasoning),
        );
        $price = $this->calculator->price($this->calculator->cost($actual, $this->prices->rates));

        expect($price)->toBeGreaterThanOrEqual($estimate->priceMinNano)
            ->toBeLessThanOrEqual($estimate->priceMaxNano);
    }
})->with(Quality::cases())->with(Size::cases());

it('exposes amounts to the UI as strings, never floats', function () {
    $data = $this->estimator->estimate(str_repeat('a', 100), Quality::Medium, Size::Square, 0, $this->prices)->toArray();

    expect($data['price_max_nano'])->toBe('86431250')
        ->and($data['price_max_display'])->toBe('$0.0864')
        ->and($data['markup_percent'])->toEqual(25)
        ->and($data['lines'])->toHaveCount(4);
});
