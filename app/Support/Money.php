<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * An exact amount of US dollars, held as integer nanodollars (1 USD = 10^9).
 */
final readonly class Money
{
    public const int NANOS_PER_DOLLAR = 1_000_000_000;

    private function __construct(public int $nanos) {}

    /**
     * Parse a decimal dollar string such as "0.046125000" (at most 9 decimals).
     * Floats are rejected: they cannot represent most amounts exactly.
     */
    public static function of(string|float $amount): self
    {
        if (is_float($amount)) {
            throw new InvalidArgumentException('Money cannot be created from a float; pass a decimal string.');
        }

        if (preg_match('/^(-)?(\d+)(?:\.(\d+))?$/', $amount, $parts) !== 1) {
            throw new InvalidArgumentException("[{$amount}] is not a decimal dollar amount.");
        }

        [, $sign, $whole] = $parts;
        $fraction = $parts[3] ?? '';

        if (strlen($fraction) > 9) {
            throw new InvalidArgumentException("[{$amount}] has more than 9 decimal places.");
        }

        if (strlen(ltrim($whole, '0')) > 9) {
            throw new InvalidArgumentException("[{$amount}] is too large.");
        }

        $nanos = (int) $whole * self::NANOS_PER_DOLLAR + (int) str_pad($fraction, 9, '0');

        return new self($sign === '-' ? -$nanos : $nanos);
    }

    public static function ofNanos(int $nanos): self
    {
        return new self($nanos);
    }

    public static function zero(): self
    {
        return new self(0);
    }

    /** The exact amount as a nine-decimal string, e.g. "0.046125000". */
    public function toDecimal(): string
    {
        $absolute = abs($this->nanos);
        $whole = intdiv($absolute, self::NANOS_PER_DOLLAR);
        $fraction = $absolute % self::NANOS_PER_DOLLAR;

        return ($this->nanos < 0 ? '-' : '').$whole.'.'.str_pad((string) $fraction, 9, '0', STR_PAD_LEFT);
    }
}
