<?php

namespace App\Contracts;

use App\Contracts\Exceptions\InsufficientCredits;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;

/**
 * Implemented by S3 (app/Billing). Append-only credit_transactions under a row
 * lock on the user; users.balance_nanos is kept equal to the ledger sum.
 * Every generation operation is idempotent (one row per generation and type).
 */
interface Ledger
{
    /** Credit config('pixelforge.welcome_credit') once per user. */
    public function grantWelcome(User $user): void;

    /** Add purchased credit. The same $token credits at most once (double-click safe). */
    public function purchase(User $user, Money $amount, string $token): void;

    /**
     * Reserve $amount (the max user price) for the generation's owner.
     *
     * @throws InsufficientCredits when available($user) < $amount
     */
    public function hold(Generation $generation, Money $amount): void;

    /** Charge the actual user price and release the hold, in one transaction. A second call is a no-op. */
    public function settle(Generation $generation, Money $charge): void;

    /** Release the hold and charge nothing (failed generations). A second call is a no-op. */
    public function release(Generation $generation): void;

    /** Balance minus open holds: what the user can still spend. */
    public function available(User $user): Money;
}
