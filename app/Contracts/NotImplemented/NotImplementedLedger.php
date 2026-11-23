<?php

namespace App\Contracts\NotImplemented;

use App\Contracts\Ledger;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;

final class NotImplementedLedger implements Ledger
{
    public function grantWelcome(User $user): void
    {
        throw NotImplementedException::for(Ledger::class, 'S3');
    }

    public function purchase(User $user, Money $amount, string $token): void
    {
        throw NotImplementedException::for(Ledger::class, 'S3');
    }

    public function hold(Generation $generation, Money $amount): void
    {
        throw NotImplementedException::for(Ledger::class, 'S3');
    }

    public function settle(Generation $generation, Money $charge): void
    {
        throw NotImplementedException::for(Ledger::class, 'S3');
    }

    public function release(Generation $generation): void
    {
        throw NotImplementedException::for(Ledger::class, 'S3');
    }

    public function available(User $user): Money
    {
        throw NotImplementedException::for(Ledger::class, 'S3');
    }
}
