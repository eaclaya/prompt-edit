<?php

namespace App\Contracts\Exceptions;

use App\Support\Money;
use RuntimeException;

/** Thrown by Ledger::hold() when the user's available credit is below the amount. */
class InsufficientCredits extends RuntimeException
{
    public function __construct(
        public readonly Money $required,
        public readonly Money $available,
    ) {
        parent::__construct("Insufficient credits: {$required->format()} required, {$available->format()} available.");
    }

    public function shortfall(): Money
    {
        return $this->required->subtract($this->available);
    }
}
