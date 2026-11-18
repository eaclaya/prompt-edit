<?php

namespace App\Listeners;

use App\Billing\Ledger;
use App\Models\User;
use App\Support\Money;
use Illuminate\Auth\Events\Registered;

/**
 * Gives every new account a small credit so it can try a generation
 * straight away. Idempotent per user.
 */
final readonly class GrantWelcomeCredit
{
    public function __construct(private Ledger $ledger) {}

    public function handle(Registered $event): void
    {
        $user = $event->user;
        $amount = Money::fromDecimal((string) config('pixelforge.welcome_credit_usd'));

        if (! $user instanceof User || $amount <= 0) {
            return;
        }

        $this->ledger->grant($user, $amount, "welcome:{$user->id}", 'Welcome credit');
    }
}
