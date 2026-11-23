<?php

namespace App\Support;

/**
 * An exact amount of US dollars, held as integer nanodollars (1 USD = 10^9).
 */
final readonly class Money
{
    public const int NANOS_PER_DOLLAR = 1_000_000_000;

    private function __construct(public int $nanos) {}

    /** Parse a decimal dollar string such as "0.046125000". */
    public static function of(string $amount): self
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $fraction = str_pad($fraction, 9, '0');

        return new self((int) $whole * self::NANOS_PER_DOLLAR + (int) $fraction);
    }

    /** The exact amount as a nine-decimal string, e.g. "0.046125000". */
    public function toDecimal(): string
    {
        $whole = intdiv($this->nanos, self::NANOS_PER_DOLLAR);
        $fraction = $this->nanos % self::NANOS_PER_DOLLAR;

        return $whole.'.'.str_pad((string) $fraction, 9, '0', STR_PAD_LEFT);
    }
}
