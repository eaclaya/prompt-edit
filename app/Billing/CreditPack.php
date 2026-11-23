<?php

namespace App\Billing;

use App\Support\Money;

/** The fake "Buy credits" packs. No real payment is taken. */
enum CreditPack: string
{
    case Usd5 = 'usd_5';
    case Usd10 = 'usd_10';
    case Usd25 = 'usd_25';

    public function amount(): Money
    {
        return Money::of(match ($this) {
            self::Usd5 => '5',
            self::Usd10 => '10',
            self::Usd25 => '25',
        });
    }

    public function label(): string
    {
        return match ($this) {
            self::Usd5 => '$5',
            self::Usd10 => '$10',
            self::Usd25 => '$25',
        };
    }

    /** @return array{id: string, amount: string, label: string} CreditPack in resources/js/types/credits.ts */
    public function toArray(): array
    {
        return ['id' => $this->value, 'amount' => $this->amount()->toDecimal(), 'label' => $this->label()];
    }
}
