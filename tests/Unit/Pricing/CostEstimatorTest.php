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
