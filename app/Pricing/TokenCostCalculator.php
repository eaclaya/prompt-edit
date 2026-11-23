<?php

namespace App\Pricing;

use App\Contracts\CostCalculator;
use App\Contracts\Data\Rates;
use App\Contracts\Data\Usage;
use App\Enums\TokenType;
use App\Support\Money;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;

/**
 * Σ tokens × rate / 1M, summed exactly and rounded up once to a whole nanodollar.
 * With rates of up to 3 decimals per 1M tokens the result is always exact.
 */
final class TokenCostCalculator implements CostCalculator
{
    public function providerCost(Usage $usage, Rates $rates): Money
    {
        $total = BigInteger::zero();

        foreach (TokenType::cases() as $type) {
            $total = $total->plus(BigInteger::of($usage->tokens($type))->multipliedBy($rates->rate($type)->nanos));
        }

        return Money::ofNanos(
            $total->dividedBy(Rates::TOKENS_PER_RATE, RoundingMode::Ceiling)->toInt(),
        );
    }
}
