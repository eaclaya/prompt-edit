<?php

namespace App\Billing;

use App\Contracts\Exceptions\InsufficientCredits;
use App\Contracts\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
        if ($charge->isNegative()) {
            throw new InvalidArgumentException("A charge cannot be negative, got {$charge->toDecimal()}.");
        }

        $this->closeHold($generation, 'settle', function (User $user, ?CreditTransaction $hold) use ($generation, $charge) {
            if ($hold && $charge->isGreaterThan($hold->amount_nanos->negate())) {
                Log::warning('Generation charged more than its hold (rates changed after the estimate).', [
                    'generation_id' => $generation->id,
                    'hold' => $hold->amount_nanos->negate()->toDecimal(),
                    'charge' => $charge->toDecimal(),
                ]);
            }

            $this->credit($user, LedgerEntryType::Charge, $charge->negate(), generation: $generation);
        });
    }

    public function release(Generation $generation): void
    {
        $this->closeHold($generation, 'release', function () {});
    }

    public function available(User $user): Money
    {
        return $this->availableFor(User::whereKey($user->getKey())->firstOrFail());
    }

    /** Cached balance plus the (negative) sum of hold and hold_release rows, i.e. minus open holds. */
    private function availableFor(User $user): Money
    {
        $reserved = (int) CreditTransaction::where('user_id', $user->id)
            ->whereIn('type', [LedgerEntryType::Hold, LedgerEntryType::HoldRelease])
            ->sum('amount_nanos');

        return $user->balance_nanos->add(Money::ofNanos($reserved));
    }

    /**
     * Release the generation's hold (if any) and run $then, all under the owner's lock.
     * A no-op when the generation was already settled or released, so a second call,
     * or a concurrent one, writes nothing. The partial unique index on
     * (generation_id, type) backs this up at the database level.
     *
     * @param  callable(User, ?CreditTransaction): void  $then
     */
    private function closeHold(Generation $generation, string $operation, callable $then): void
    {
        $owner = $generation->user;

        if (! $owner) {
            Log::warning("Ledger {$operation} skipped: the generation has no owner.", ['generation_id' => $generation->id]);

            return;
        }

        try {
            $this->locked($owner, function (User $user) use ($generation, $operation, $then) {
                if ($this->entry($generation, LedgerEntryType::Charge) || $this->entry($generation, LedgerEntryType::HoldRelease)) {
                    return;
                }

                $hold = $this->entry($generation, LedgerEntryType::Hold);

                if ($hold) {
                    $this->reserve($user, $generation, LedgerEntryType::HoldRelease, $hold->amount_nanos->negate());
                } elseif ($operation === 'release') {
                    return;
                }

                $then($user, $hold);
            });
        } catch (UniqueConstraintViolationException) {
            // Another writer closed this hold first; the transaction rolled back whole.
        }
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
    private function credit(User $user, LedgerEntryType $type, Money $amount, ?string $key = null, ?Generation $generation = null): void
    {
        $balance = $user->balance_nanos->add($amount);

        CreditTransaction::create([
            'user_id' => $user->id,
            'generation_id' => $generation?->id,
            'type' => $type,
            'amount_nanos' => $amount,
            'balance_after_nanos' => $balance,
            'idempotency_key' => $key,
        ]);

        $user->forceFill(['balance_nanos' => $balance])->save();
    }
}
