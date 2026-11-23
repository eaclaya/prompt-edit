<?php

namespace App\Contracts\Data;

use App\Support\Money;

/**
 * A cost range for a request. Provider values are what Pixelforge would bill;
 * user values are after the markup. The user's max is the amount held on submit.
 */
final readonly class Estimate
{
    public function __construct(
        public Money $providerMin,
        public Money $providerExpected,
        public Money $providerMax,
        public Money $userMin,
        public Money $userExpected,
        public Money $userMax,
    ) {}

    /**
     * The user prices, as decimal strings (the POST /estimate shape; never shows provider cost).
     *
     * @return array{min: string, expected: string, max: string}
     */
    public function toArray(): array
    {
        return [
            'min' => $this->userMin->toDecimal(),
            'expected' => $this->userExpected->toDecimal(),
            'max' => $this->userMax->toDecimal(),
        ];
    }
}
