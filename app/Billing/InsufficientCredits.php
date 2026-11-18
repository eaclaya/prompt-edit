<?php

namespace App\Billing;

use App\Support\Money;
use RuntimeException;

final class InsufficientCredits extends RuntimeException
{
    public function __construct(
        public readonly int $requiredNano,
        public readonly int $availableNano,
    ) {
        parent::__construct(sprintf(
            'This generation can cost up to %s but your balance is %s.',
            Money::format($requiredNano),
            Money::format($availableNano),
        ));
    }
}
