<?php

namespace App\Contracts;

use App\Support\Money;

/** Implemented by S2 (app/Pricing). */
interface Markup
{
    /** The user price: providerCost × (10000 + markup_bps) / 10000, rounded up to the nanodollar. Zero stays zero. */
    public function apply(Money $providerCost): Money;
}
