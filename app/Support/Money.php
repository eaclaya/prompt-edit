<?php

namespace App\Support;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Money is stored and computed as integer nano-dollars (1 = $0.000000001).
 *
 * Pixelforge reports costs with exactly nine decimal places, so every
 * provider amount is a whole number of nano-dollars and integer arithmetic
 * stays exact. Rounding happens only when formatting for display.
 */
final class Money
{
    public const SCALE = 9;

    public const NANO_PER_DOLLAR = 1_000_000_000;

    /**
     * Parse a USD decimal string ("0.169080000", "2.00") into nano-dollars.
     *
     * @throws InvalidArgumentException when the amount has more than nine decimals
     */
    public static function fromDecimal(string $usd): int
    {
        try {
            return BigDecimal::of($usd)
                ->toScale(self::SCALE, RoundingMode::Unnecessary)
                ->getUnscaledValue()
                ->toInt();
        } catch (RoundingNecessaryException) {
            throw new InvalidArgumentException("Amount [{$usd}] is more precise than a nano-dollar.");
        }
    }

    /**
     * Render nano-dollars as a plain decimal string, e.g. 169080000 -> "0.169080000".
     *
     * @param  int<0, max>  $scale
     */
    public static function toDecimal(int $nano, int $scale = self::SCALE): string
    {
        return (string) BigDecimal::ofUnscaledValue($nano, self::SCALE)
            ->toScale($scale, RoundingMode::HalfUp);
    }

    /**
     * Human-readable dollars, e.g. 86431250 -> "$0.0864". Display only.
     *
     * @param  int<0, max>  $decimals
     */
    public static function format(int $nano, int $decimals = 4): string
    {
        $sign = $nano < 0 ? '-' : '';

        return $sign.'$'.self::toDecimal(abs($nano), $decimals);
    }

    /**
     * Add a markup expressed in basis points (2500 = 25%), rounding up to the
     * next nano-dollar so we never undercharge. At Pixelforge's current rates
     * every cost is divisible by 4, so a 25% markup is exact and never rounds.
     */
    public static function applyMarkup(int $nano, int $basisPoints): int
    {
        if ($nano < 0 || $basisPoints < 0) {
            throw new InvalidArgumentException('Markup requires a non-negative amount and rate.');
        }

        $numerator = $nano * (10_000 + $basisPoints);

        return intdiv($numerator + 9_999, 10_000);
    }
}
