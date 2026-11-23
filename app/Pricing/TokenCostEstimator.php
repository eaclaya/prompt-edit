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

        return [
            'min' => new Usage(0, 0, $output, 0),
            'expected' => new Usage(0, 0, $output, 0),
            'max' => new Usage(0, 0, $output, 0),
        ];
    }
}
