<?php

namespace App\Pricing;

use App\Enums\Quality;
use App\Enums\Size;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Estimates what a generation will cost before it is submitted.
 *
 * Reference-image and output tokens are known exactly. Text tokens
 * (≈ characters / 4, actual within ±15%) and reasoning tokens (a range per
 * quality) are only known afterwards, so the estimate is a min–max range.
 */
final readonly class CostEstimator
{
    public function __construct(private CostCalculator $calculator) {}

    public function estimate(
        string $prompt,
        Quality $quality,
        Size $size,
        int $referenceImageCount,
        PriceList $prices,
    ): Estimate {
        [$textMin, $textMax] = $this->textTokenRange($prompt, $prices);
        [$reasoningMin, $reasoningMax] = $prices->reasoningTokenRange($quality);
        $imageTokens = $referenceImageCount * $prices->tokensPerReferenceImage();
        $outputTokens = $prices->outputTokens($quality, $size);

        $min = new TokenUsage($textMin, $imageTokens, $outputTokens, $reasoningMin);
        $max = new TokenUsage($textMax, $imageTokens, $outputTokens, $reasoningMax);

        $costMin = $this->calculator->cost($min, $prices->rates);
        $costMax = $this->calculator->cost($max, $prices->rates);

        return new Estimate(
            minTokens: $min,
            maxTokens: $max,
            costMinNano: $costMin,
            costMaxNano: $costMax,
            priceMinNano: $this->calculator->price($costMin),
            priceMaxNano: $this->calculator->price($costMax),
            rates: $prices->rates,
            markupBasisPoints: $this->calculator->markupBasisPoints(),
        );
    }

    /**
     * Text tokens: estimate = ceil(characters / 4), counting Unicode
     * characters rather than bytes; the actual count is within ±variance of
     * that. Exact decimal arithmetic avoids float errors such as
     * 100 × 0.85 = 84.999….
     *
     * @return array{0: int, 1: int} [min, max]
     */
    public function textTokenRange(string $prompt, PriceList $prices): array
    {
        $characters = mb_strlen($prompt, 'UTF-8');
        $perToken = $prices->charactersPerTextToken();
        $estimate = intdiv($characters + $perToken - 1, $perToken);

        $variance = BigDecimal::of($prices->textTokenVariance());
        $min = BigDecimal::of($estimate)->multipliedBy(BigDecimal::one()->minus($variance))
            ->toScale(0, RoundingMode::Floor)->toInt();
        $max = BigDecimal::of($estimate)->multipliedBy(BigDecimal::one()->plus($variance))
            ->toScale(0, RoundingMode::Ceiling)->toInt();

        return [max(0, $min), $max];
    }
}
