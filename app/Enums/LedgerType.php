<?php

namespace App\Enums;

/**
 * Kinds of balance movement. Credits add to the balance, debits subtract.
 *
 * A generation has at most one hold, one release and one charge, enforced by
 * a unique index on (generation_id, type).
 */
enum LedgerType: string
{
    case Grant = 'grant';
    case Purchase = 'purchase';
    case Hold = 'hold';
    case Release = 'release';
    case Charge = 'charge';

    /** +1 for entries that add to the balance, -1 for those that subtract. */
    public function sign(): int
    {
        return match ($this) {
            self::Grant, self::Purchase, self::Release => 1,
            self::Hold, self::Charge => -1,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Grant => 'Free credit',
            self::Purchase => 'Credit purchase',
            self::Hold => 'Reserved for generation',
            self::Release => 'Reservation released',
            self::Charge => 'Generation charge',
        };
    }
}
