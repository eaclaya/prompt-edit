<?php

namespace App\Pricing;

use App\Contracts\Markup;
use App\Support\Money;
use InvalidArgumentException;

/** providerCost × (10000 + bps) / 10000, rounded up to the nanodollar (always in our favour). */
final readonly class BasisPointsMarkup implements Markup
{
    private const int BASIS_POINTS = 10_000;

    public function __construct(private int $markupBps)
    {
        if ($markupBps < 0) {
            throw new InvalidArgumentException("The markup must not be negative, got [{$markupBps}] bps.");
        }
    }

    public function apply(Money $providerCost): Money
    {
        return $providerCost->multiplyRatio(self::BASIS_POINTS + $this->markupBps, self::BASIS_POINTS);
    }
}
