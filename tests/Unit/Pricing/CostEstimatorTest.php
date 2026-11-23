<?php

use App\Contracts\Data\EstimateInput;
use App\Contracts\Data\Usage;
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

it('gives the docs worked example a max of exactly $0.373875', function () {
    // high, 1536x1024, 400 characters, 2 reference images:
    // 115 text (ceil(100 × 1.15)) + 1530 image + 6450 output + 8000 reasoning (max for high)
    $input = new EstimateInput(str_repeat('a', 400), Size::Landscape, Quality::High, 2);

    $max = estimator()->usageBounds($input)['max'];
    $estimate = estimator()->estimate($input);

    expect($max->toArray())->toBe([
        'input_text_tokens' => 115,
        'input_image_tokens' => 1530,
        'output_tokens' => 6450,
        'reasoning_tokens' => 8000,
        'total_tokens' => 16095,
    ])
        ->and($estimate->providerMax)->toEqualMoney('0.373875')
        ->and($estimate->userMax)->toEqualMoney('0.46734375');
});

it('uses ×0.85 text tokens and the minimum reasoning tokens for the min', function () {
    $input = new EstimateInput(str_repeat('a', 400), Size::Landscape, Quality::High, 2);

    $min = estimator()->usageBounds($input)['min'];
    $estimate = estimator()->estimate($input);

    expect($min->inputTextTokens)->toBe(85)
        ->and($min->reasoningTokens)->toBe(1000)
        ->and($estimate->providerMin)->toEqualMoney('0.286225')
        ->and($estimate->userMin)->toEqualMoney('0.35778125');
});

it('expects the midpoint of the reasoning range', function () {
    $input = new EstimateInput(str_repeat('a', 400), Size::Landscape, Quality::High, 2);

    $estimate = estimator()->estimate($input);

    expect(estimator()->usageBounds($input)['expected']->reasoningTokens)->toBe(4500)
        ->and($estimate->providerExpected)->toEqualMoney('0.33005')
        ->and($estimate->userExpected)->toEqualMoney('0.4125625');
});

it('rounds the text bounds outwards so they always contain the real count', function () {
    // 5 characters → 2 tokens; 2 × 1.15 = 2.3 → 3 at most, 2 × 0.85 = 1.7 → 1 at least.
    $bounds = estimator()->usageBounds(new EstimateInput('abcde', Size::Square, Quality::Low));

    expect($bounds['min']->inputTextTokens)->toBe(1)
        ->and($bounds['max']->inputTextTokens)->toBe(3);
});

it('orders min ≤ expected ≤ max for every quality, size and reference count', function (Quality $quality, Size $size) {
    foreach ([0, 1, 4] as $references) {
        foreach (['', 'a', str_repeat('lighthouse at dusk ', 50), str_repeat('猫', 4000)] as $prompt) {
            $estimate = estimator()->estimate(new EstimateInput($prompt, $size, $quality, $references));

            expect($estimate->providerMin->isLessThanOrEqualTo($estimate->providerExpected))->toBeTrue()
                ->and($estimate->providerExpected->isLessThanOrEqualTo($estimate->providerMax))->toBeTrue()
                ->and($estimate->userMin->isLessThanOrEqualTo($estimate->userExpected))->toBeTrue()
                ->and($estimate->userExpected->isLessThanOrEqualTo($estimate->userMax))->toBeTrue()
                ->and($estimate->userMax->isGreaterThanOrEqualTo($estimate->providerMax))->toBeTrue();
        }
    }
})->with(Quality::cases())->with(Size::cases());

it('prices any real usage within the bounds between min and max (property test)', function () {
    mt_srand(20261123);

    $calculator = new TokenCostCalculator;
    $markup = new BasisPointsMarkup(2500);
    $cents = fn (): string => sprintf('%d.%09d', mt_rand(0, 99), mt_rand(0, 999_999_999));

    for ($run = 0; $run < 500; $run++) {
        // Arbitrary rates, down to the nanodollar, so rounding is exercised too.
        $catalog = (new FakePricingCatalog)->withRates([
            'input_text_tokens' => $cents(),
            'input_image_tokens' => $cents(),
            'output_tokens' => $cents(),
            'reasoning_tokens' => $cents(),
        ]);
        $estimator = new TokenCostEstimator($catalog, $calculator, $markup);

        $quality = Quality::cases()[mt_rand(0, 2)];
        $input = new EstimateInput(
            prompt: str_repeat(['a', 'é', '猫', '🐈'][mt_rand(0, 3)], mt_rand(0, 4000)),
            size: Size::cases()[mt_rand(0, 2)],
            quality: $quality,
            referenceImageCount: mt_rand(0, 4),
        );

        $estimate = $estimator->estimate($input);
        $model = $catalog->tokenModel();
        $text = (int) ceil($input->promptCharacters() / 4);

        // What Pixelforge might really report: exact output and image tokens,
        // text within ±15% of ceil(chars / 4), reasoning anywhere in the quality's range.
        $actual = new Usage(
            inputTextTokens: mt_rand((int) ceil($text * 0.85), (int) floor($text * 1.15)),
            inputImageTokens: $input->referenceImageCount * 765,
            outputTokens: $model->outputTokens($quality, $input->size),
            reasoningTokens: mt_rand($model->minReasoningTokens($quality), $model->maxReasoningTokens($quality)),
        );

        $provider = $calculator->providerCost($actual, $catalog->current());
        $user = $markup->apply($provider);

        expect($provider->isGreaterThanOrEqualTo($estimate->providerMin))->toBeTrue("run {$run}: provider below min")
            ->and($provider->isLessThanOrEqualTo($estimate->providerMax))->toBeTrue("run {$run}: provider above max")
            ->and($user->isGreaterThanOrEqualTo($estimate->userMin))->toBeTrue("run {$run}: user below min")
            ->and($user->isLessThanOrEqualTo($estimate->userMax))->toBeTrue("run {$run}: user above max");
    }
});
