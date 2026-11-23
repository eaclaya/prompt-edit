<?php

namespace App\Contracts\NotImplemented;

use App\Contracts\Markup;
use App\Support\Money;

final class NotImplementedMarkup implements Markup
{
    public function apply(Money $providerCost): Money
    {
        throw NotImplementedException::for(Markup::class, 'S2');
    }
}
