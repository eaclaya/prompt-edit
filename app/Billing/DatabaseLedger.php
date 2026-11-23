<?php

namespace App\Billing;

use App\Contracts\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * The append-only ledger in credit_transactions.
 *
 * Every operation runs in one transaction that first locks the owner's users
 * row (lockForUpdate), so operations on one user are serialised and each
 * idempotency check sees every committed row.
 */
final class DatabaseLedger implements Ledger
{
    public function grantWelcome(User $user): void
    {
        $this->locked($user, function (User $user) {
            $key = "welcome:{$user->id}";

            if (CreditTransaction::where('idempotency_key', $key)->exists()) {
                return;
            }

            $this->credit($user, LedgerEntryType::Welcome, Money::of((string) config('pixelforge.welcome_credit')), key: $key);
        });
    }

    public function purchase(User $user, Money $amount, string $token): void
    {
        throw new \LogicException('Not implemented.');
    }

    public function hold(Generation $generation, Money $amount): void
    {
        throw new \LogicException('Not implemented.');
    }

    public function settle(Generation $generation, Money $charge): void
    {
        throw new \LogicException('Not implemented.');
    }

    public function release(Generation $generation): void
    {
        throw new \LogicException('Not implemented.');
    }

    public function available(User $user): Money
    {
        throw new \LogicException('Not implemented.');
    }

    /**
     * Run $callback in a transaction holding the row lock on the user.
     *
     * @param  callable(User): void  $callback
     */
    private function locked(User $user, callable $callback): void
    {
        DB::transaction(function () use ($user, $callback) {
            $locked = User::whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $callback($locked);

            $user->setRawAttributes($locked->getAttributes(), sync: true);
        });
    }

    /** Append a row that moves the balance, and update the cached balance. Caller holds the lock. */
    private function credit(User $user, LedgerEntryType $type, Money $amount, ?string $key = null): void
    {
        $balance = $user->balance_nanos->add($amount);

        CreditTransaction::create([
            'user_id' => $user->id,
            'type' => $type,
            'amount_nanos' => $amount,
            'balance_after_nanos' => $balance,
            'idempotency_key' => $key,
        ]);

        $user->forceFill(['balance_nanos' => $balance])->save();
    }
}
