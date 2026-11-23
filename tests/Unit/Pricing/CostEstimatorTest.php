<?php

use App\Contracts\Data\EstimateInput;
use App\Enums\Quality;
use App\Enums\Size;
use App\Pricing\BasisPointsMarkup;
use App\Pricing\TokenCostCalculator;
use App\Pricing\TokenCostEstimator;
use Tests\Fakes\FakePricingCatalog;

function estimator(): TokenCostEstimator
{
    return new TokenCostEstimator(new FakePricingCatalog, new TokenCostCalculator, new BasisPointsMarkup(2500));
}

it('takes output tokens from the quality × size table', function (Quality $quality, Size $size, int $tokens) {
    $bounds = estimator()->usageBounds(new EstimateInput('a cat', $size, $quality));

    expect($bounds['min']->outputTokens)->toBe($tokens)
        ->and($bounds['expected']->outputTokens)->toBe($tokens)
        ->and($bounds['max']->outputTokens)->toBe($tokens);
})->with([
    [Quality::Low, Size::Square, 280],
    [Quality::Low, Size::Landscape, 420],
    [Quality::Medium, Size::Square, 1100],
    [Quality::Medium, Size::Portrait, 1650],
    [Quality::High, Size::Square, 4300],
    [Quality::High, Size::Landscape, 6450],
]);

it('adds 765 image tokens per reference image', function (int $references) {
    $bounds = estimator()->usageBounds(new EstimateInput('a cat', Size::Square, Quality::Low, $references));

    expect($bounds['min']->inputImageTokens)->toBe(765 * $references)
        ->and($bounds['expected']->inputImageTokens)->toBe(765 * $references)
        ->and($bounds['max']->inputImageTokens)->toBe(765 * $references);
})->with([0, 1, 2, 4]);

it('expects ceil(chars / 4) text tokens', function (int $characters, int $tokens) {
    $bounds = estimator()->usageBounds(new EstimateInput(str_repeat('a', $characters), Size::Square, Quality::Low));

    expect($bounds['expected']->inputTextTokens)->toBe($tokens);
})->with([[0, 0], [1, 1], [4, 1], [5, 2], [400, 100], [4000, 1000]]);

it('counts Unicode characters, not bytes', function () {
    // 8 characters, 25 bytes in UTF-8: each 猫 is 3 bytes, the emoji 4.
    $prompt = str_repeat('猫', 7).'🐈';

    expect(strlen($prompt))->toBe(25)
        ->and(estimator()->usageBounds(new EstimateInput($prompt, Size::Square, Quality::Low))['expected']->inputTextTokens)
        ->toBe(2);
});
