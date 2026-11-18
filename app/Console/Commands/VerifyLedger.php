<?php

namespace App\Console\Commands;

use App\Billing\Ledger;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ledger:verify')]
#[Description('Check that every cached balance equals the sum of its ledger entries')]
class VerifyLedger extends Command
{
    public function handle(Ledger $ledger): int
    {
        $problems = $ledger->discrepancies();

        if ($problems->isEmpty()) {
            $this->info('All balances match their ledger entries.');

            return self::SUCCESS;
        }

        $this->error("{$problems->count()} balance(s) do not match their ledger entries.");
        $this->table(
            ['User', 'Cached balance', 'Ledger sum'],
            $problems->map(fn (array $row) => [
                $row['user_id'],
                Money::format($row['balance_nano'], 9),
                Money::format($row['ledger_sum'], 9),
            ]),
        );

        return self::FAILURE;
    }
}
