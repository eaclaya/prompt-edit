<?php

namespace Tests\Fakes;

use App\Contracts\Markup;
use App\Support\Money;

/** × (1 + markup_bps / 10000), rounded up; or a fixed amount. */
final class FakeMarkup implements Markup
{
    private ?Money $fixed = null;

    public static function swap(): self
    {
        return app()->instance(Markup::class, new self);
    }

    public function returns(Money $price): self
    {
        $this->fixed = $price;

        return $this;
    }

    public function apply(Money $providerCost): Money
    {
        return $this->fixed ?? $providerCost->multiplyRatio(10_000 + (int) config('pixelforge.markup_bps'), 10_000);
    }
}
