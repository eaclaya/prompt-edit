<?php

namespace App\Pricing;

use App\Enums\TokenType;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Container\Attributes\Config;
use InvalidArgumentException;

/**
 * Turns token counts into money. The only place that knows how a cost is
 * computed, so estimates and actual charges can never disagree.
 */
final readonly class CostCalculator
{
    public function __construct(
        #[Config('pixelforge.markup_bps')]
        private int $markupBasisPoints,
    ) {}

    /**
     * Pixelforge's cost in nano-dollars: Σ tokens × (USD per 1M tokens) / 1M.
     *
     * A rate of $R per 1M tokens is R × 1000 nano-dollars per token, so the
     * sum is exact for any rate with up to three decimals. If a future rate
     * is finer than that, the total is rounded up once, at the end.
     *
     * @param  array<string, string>  $rates  USD per 1M tokens, keyed by TokenType value
     */
    public function cost(TokenUsage $usage, array $rates): int
    {
        $total = BigDecimal::zero();

        foreach (TokenType::cases() as $type) {
            if (! isset($rates[$type->value])) {
                throw new InvalidArgumentException("Missing rate for [{$type->value}].");
            }

            $total = $total->plus(
                BigDecimal::of($rates[$type->value])
                    ->multipliedBy($usage->get($type))
                    ->multipliedBy(1000)
            );
        }

        return $total->toScale(0, RoundingMode::Up)->toInt();
    }

    /** What we charge the user for a given provider cost. */
    public function price(int $costNano): int
    {
        return Money::applyMarkup($costNano, $this->markupBasisPoints);
    }

    public function markupBasisPoints(): int
    {
        return $this->markupBasisPoints;
    }
}
