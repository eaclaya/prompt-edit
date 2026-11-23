<?php

use App\Contracts\Exceptions\InsufficientCredits;
use App\Contracts\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;

/**
 * The ledger's invariants, checked from the raw rows:
 *  - users.balance_nanos == Σ amount of the balance rows (welcome, purchase, charge)
 *  - available() == Σ amount of every row (holds and releases net out once closed)
 *  - each balance row's balance_after is the running sum of the balance rows up to it
 */
function assertLedgerInvariant(User $user): void
{
    $rows = CreditTransaction::where('user_id', $user->id)->orderBy('id')->get();
    $balanceTypes = [LedgerEntryType::Welcome, LedgerEntryType::Purchase, LedgerEntryType::Charge];

    $running = Money::zero();
    foreach ($rows as $row) {
        if (in_array($row->type, $balanceTypes, true)) {
            $running = $running->add($row->amount_nanos);
        }
        expect($row->balance_after_nanos)->toEqualMoney($running);
    }

    expect($user)->toHaveBalance($running)
        ->and(app(Ledger::class)->available($user))->toEqualMoney(Money::sum($rows->pluck('amount_nanos')->all()));
}

it('keeps the cached balance equal to the ledger sum after every operation', function () {
    $ledger = app(Ledger::class);
    $user = User::factory()->create();
    [$a, $b, $c, $d] = Generation::factory()->for($user)->count(4)->create()->all();

    $steps = [
        fn () => $ledger->grantWelcome($user),
        fn () => $ledger->purchase($user, Money::of('5.00'), 'tok-5'),
        fn () => $ledger->purchase($user, Money::of('5.00'), 'tok-5'),
        fn () => $ledger->hold($a, Money::of('0.46734375')),
        fn () => $ledger->hold($b, Money::of('0.086343750')),
        fn () => $ledger->hold($c, Money::of('0.30')),
        fn () => $ledger->settle($a, Money::of('0.3818875')),
        fn () => $ledger->settle($a, Money::of('0.3818875')),
        fn () => $ledger->release($b),
        fn () => $ledger->settle($b, Money::of('0.05')),
        fn () => $ledger->settle($c, Money::of('0.31')),
        fn () => $ledger->settle($d, Money::of('0.01')),
        fn () => $ledger->grantWelcome($user),
    ];

    foreach ($steps as $step) {
        $step();
        assertLedgerInvariant($user);
    }

    expect($user)->toHaveBalance('5.2981125');
});

it('keeps the invariant through a random sequence of operations', function () {
    $ledger = app(Ledger::class);
    $user = User::factory()->create();
    $ledger->grantWelcome($user);
    $generations = Generation::factory()->for($user)->count(12)->create()->all();
    mt_srand(20261123);

    for ($i = 0; $i < 60; $i++) {
        $generation = $generations[mt_rand(0, count($generations) - 1)];
        $amount = Money::ofNanos(mt_rand(1, 400_000_000));

        try {
            match (mt_rand(0, 3)) {
                0 => $ledger->purchase($user, $amount, 'tok-'.mt_rand(1, 5)),
                1 => $ledger->hold($generation, $amount),
                2 => $ledger->settle($generation, $amount),
                3 => $ledger->release($generation),
            };
        } catch (InsufficientCredits) {
            // a refused hold must leave nothing behind, which the invariant checks
        }

        assertLedgerInvariant($user);
    }
});
