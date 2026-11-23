<?php

namespace Database\Factories;

use App\Enums\LedgerEntryType;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Raw ledger rows for display tests. Balances are not recomputed: use the Ledger for that.
 *
 * @extends Factory<CreditTransaction>
 */
class CreditTransactionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'generation_id' => null,
            'type' => LedgerEntryType::Purchase,
            'amount_nanos' => Money::of('10.00'),
            'balance_after_nanos' => Money::of('10.00'),
            'meta' => null,
        ];
    }
}
