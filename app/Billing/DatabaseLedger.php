<?php

namespace App\Billing;

use App\Contracts\Exceptions\InsufficientCredits;
use App\Contracts\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

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
        if (! $amount->isPositive()) {
            throw new InvalidArgumentException("A purchase must be positive, got {$amount->toDecimal()}.");
        }

        $this->locked($user, function (User $user) use ($amount, $token) {
            if (CreditTransaction::where('idempotency_key', $token)->exists()) {
                return;
            }

            $this->credit($user, LedgerEntryType::Purchase, $amount, key: $token);
        });
    }

    public function hold(Generation $generation, Money $amount): void
    {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException("A hold cannot be negative, got {$amount->toDecimal()}.");
        }

        $this->locked($this->owner($generation), function (User $user) use ($generation, $amount) {
            if ($this->entry($generation, LedgerEntryType::Hold)) {
                return;
            }

            $available = $this->availableFor($user);

            if ($available->isLessThan($amount)) {
                throw new InsufficientCredits($amount, $available);
            }

            $this->reserve($user, $generation, LedgerEntryType::Hold, $amount->negate());
        });
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
        return $this->availableFor($user->newQuery()->findOrFail($user->getKey()));
    }

    /** Cached balance plus the (negative) sum of hold and hold_release rows, i.e. minus open holds. */
    private function availableFor(User $user): Money
    {
        $reserved = (int) CreditTransaction::where('user_id', $user->id)
            ->whereIn('type', [LedgerEntryType::Hold, LedgerEntryType::HoldRelease])
            ->sum('amount_nanos');

        return $user->balance_nanos->add(Money::ofNanos($reserved));
    }

    private function owner(Generation $generation): User
    {
        return $generation->user ?? throw new LogicException("Generation [{$generation->id}] has no user.");
    }

    private function entry(Generation $generation, LedgerEntryType $type): ?CreditTransaction
    {
        return CreditTransaction::where('generation_id', $generation->id)->where('type', $type)->first();
    }

    /** Append a hold or hold_release row: it moves "available", not the balance. Caller holds the lock. */
    private function reserve(User $user, Generation $generation, LedgerEntryType $type, Money $amount): void
    {
        CreditTransaction::create([
            'user_id' => $user->id,
            'generation_id' => $generation->id,
            'type' => $type,
            'amount_nanos' => $amount,
            'balance_after_nanos' => $user->balance_nanos,
        ]);
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
