<?php

namespace App\Pricing;

use App\Contracts\CostCalculator;
use App\Contracts\CostEstimator;
use App\Contracts\Data\Estimate;
use App\Contracts\Data\EstimateInput;
use App\Contracts\Data\Usage;
use App\Contracts\Markup;
use App\Contracts\PricingCatalog;
use App\Support\Money;

/**
 * Estimates a request from today's rates and token model:
 * output and image tokens are exact, text tokens vary by ± the documented
 * variance and reasoning tokens fall in the per-quality range.
 */
final readonly class TokenCostEstimator implements CostEstimator
{
    private const int BASIS_POINTS = 10_000;

    public function __construct(
        private PricingCatalog $catalog,
        private CostCalculator $calculator,
        private Markup $markup,
    ) {}

    public function estimate(EstimateInput $input): Estimate
    {
        $rates = $this->catalog->current();
        $bounds = $this->usageBounds($input);

        $provider = array_map(fn (Usage $usage): Money => $this->calculator->providerCost($usage, $rates), $bounds);

        return new Estimate(
            providerMin: $provider['min'],
            providerExpected: $provider['expected'],
            providerMax: $provider['max'],
            userMin: $this->markup->apply($provider['min']),
            userExpected: $this->markup->apply($provider['expected']),
            userMax: $this->markup->apply($provider['max']),
        );
    }

    /**
     * The token counts behind the estimate.
     *
     * @return array{min: Usage, expected: Usage, max: Usage}
     */
    public function usageBounds(EstimateInput $input): array
    {
        $model = $this->catalog->tokenModel();
        $output = $model->outputTokens($input->quality, $input->size);
        $images = $input->referenceImageCount * $model->perReferenceImage;
        $text = self::ceilDiv($input->promptCharacters(), $model->charactersPerTextToken);
        $variance = $model->textTokenVarianceBps;
        $minReasoning = $model->minReasoningTokens($input->quality);
        $maxReasoning = $model->maxReasoningTokens($input->quality);

        return [
            // Bounds round outwards, so the real count is always inside them.
            'min' => new Usage(
                intdiv($text * (self::BASIS_POINTS - $variance), self::BASIS_POINTS),
                $images,
                $output,
                $minReasoning,
            ),
            'expected' => new Usage($text, $images, $output, intdiv($minReasoning + $maxReasoning, 2)),
            'max' => new Usage(
                self::ceilDiv($text * (self::BASIS_POINTS + $variance), self::BASIS_POINTS),
                $images,
                $output,
                $maxReasoning,
            ),
        ];
    }

    private static function ceilDiv(int $dividend, int $divisor): int
    {
        return intdiv($dividend + $divisor - 1, $divisor);
    }
}
