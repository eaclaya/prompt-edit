<?php

/*
 * A separate PHP process that runs one Ledger operation against the committed
 * database, for LedgerConcurrencyTest. Exit codes: 0 done, 3 InsufficientCredits.
 *
 *   php worker.php settle <generation id> <amount>
 *   php worker.php hold   <generation id> <amount>
 */

use App\Contracts\Exceptions\InsufficientCredits;
use App\Contracts\Ledger;
use App\Models\Generation;
use App\Support\Money;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../../../vendor/autoload.php';

$app = require __DIR__.'/../../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $operation, $generationId, $amount] = $argv;

$ledger = $app->make(Ledger::class);
$generation = Generation::findOrFail($generationId);

try {
    match ($operation) {
        'settle' => $ledger->settle($generation, Money::of($amount)),
        'hold' => $ledger->hold($generation, Money::of($amount)),
    };
} catch (InsufficientCredits) {
    exit(3);
}

exit(0);
