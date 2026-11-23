<?php

namespace App\Listeners;

use App\Contracts\Ledger;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

/** Every new account starts with config('pixelforge.welcome_credit'). Discovered automatically. */
class GrantWelcomeCredit
{
    public function __construct(private readonly Ledger $ledger) {}

    public function handle(Registered $event): void
    {
        if ($event->user instanceof User) {
            $this->ledger->grantWelcome($event->user);
        }
    }
}
