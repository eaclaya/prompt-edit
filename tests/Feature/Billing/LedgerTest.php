<?php

use App\Billing\DatabaseLedger;
use App\Contracts\Exceptions\InsufficientCredits;
use App\Contracts\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;

/** A user whose balance came through the ledger. */
function fundedUser(string $amount = '1.00'): User
{
    $user = User::factory()->create();
    app(Ledger::class)->purchase($user, Money::of($amount), 'seed-'.$user->id);

    return $user;
}

function generationFor(User $user): Generation
{
    return Generation::factory()->for($user)->create();
}

function ledger(): Ledger
{
    return app(Ledger::class);
}

/** @return list<LedgerEntryType> */
function entryTypes(Generation $generation): array
{
    return CreditTransaction::where('generation_id', $generation->id)->orderBy('id')->get()->pluck('type')->all();
}

it('is the database ledger', function () {
    expect(ledger())->toBeInstanceOf(DatabaseLedger::class)
        ->and(ledger())->toBe(ledger());
});

describe('purchase', function () {
    it('adds credit and records balance_after', function () {
        $user = User::factory()->create();

        ledger()->purchase($user, Money::of('10.00'), 'tok-1');
        ledger()->purchase($user, Money::of('5.00'), 'tok-2');

        expect($user)->toHaveBalance('15.00');
        $rows = $user->creditTransactions()->orderBy('id')->get();
        expect($rows)->toHaveCount(2)
            ->and($rows[0]->type)->toBe(LedgerEntryType::Purchase)
            ->and($rows[0]->amount_nanos)->toEqualMoney('10.00')
            ->and($rows[0]->balance_after_nanos)->toEqualMoney('10.00')
            ->and($rows[0]->idempotency_key)->toBe('tok-1')
            ->and($rows[1]->balance_after_nanos)->toEqualMoney('15.00');
    });

    it('credits the same token once', function () {
        $user = User::factory()->create();

        ledger()->purchase($user, Money::of('10.00'), 'tok-1');
        ledger()->purchase($user, Money::of('10.00'), 'tok-1');

        expect($user)->toHaveBalance('10.00')
            ->and($user->creditTransactions()->count())->toBe(1);
    });

    it('rejects a zero or negative amount', function (string $amount) {
        ledger()->purchase(User::factory()->create(), Money::of($amount), 'tok');
    })->with(['0', '-1.00'])->throws(InvalidArgumentException::class);

    it('keeps the passed user model in sync with the cached balance', function () {
        $user = User::factory()->create();

        ledger()->purchase($user, Money::of('2.00'), 'tok');

        expect($user->balance_nanos)->toEqualMoney('2.00');
    });
});

describe('hold', function () {
    it('reserves the amount without changing the balance', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);

        ledger()->hold($generation, Money::of('0.40'));

        expect($user)->toHaveBalance('1.00')
            ->and(ledger()->available($user))->toEqualMoney('0.60');
        $hold = CreditTransaction::where('generation_id', $generation->id)->sole();
        expect($hold->type)->toBe(LedgerEntryType::Hold)
            ->and($hold->user_id)->toBe($user->id)
            ->and($hold->amount_nanos)->toEqualMoney('-0.40')
            ->and($hold->balance_after_nanos)->toEqualMoney('1.00');
    });

    it('throws InsufficientCredits when available is too low, and holds nothing', function () {
        $user = fundedUser('1.00');
        ledger()->hold(generationFor($user), Money::of('0.70'));
        $generation = generationFor($user);

        expect(fn () => ledger()->hold($generation, Money::of('0.40')))
            ->toThrow(function (InsufficientCredits $e) {
                expect($e->required)->toEqualMoney('0.40')
                    ->and($e->available)->toEqualMoney('0.30');
            });
        expect(entryTypes($generation))->toBe([]);
    });

    it('allows holding exactly what is available', function () {
        $user = fundedUser('0.50');

        ledger()->hold(generationFor($user), Money::of('0.50'));

        expect(ledger()->available($user))->toEqualMoney('0');
    });

    it('places one hold per generation', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);

        ledger()->hold($generation, Money::of('0.40'));
        ledger()->hold($generation, Money::of('0.40'));

        expect(entryTypes($generation))->toBe([LedgerEntryType::Hold])
            ->and(ledger()->available($user))->toEqualMoney('0.60');
    });

    it('only counts the owner\'s holds against their available credit', function () {
        $alice = fundedUser('1.00');
        $bob = fundedUser('1.00');

        ledger()->hold(generationFor($alice), Money::of('0.90'));

        expect(ledger()->available($bob))->toEqualMoney('1.00');
    });

    it('treats a cached balance with no ledger rows as spendable', function () {
        $user = User::factory()->withBalance('0.50')->create();

        ledger()->hold(generationFor($user), Money::of('0.20'));

        expect(ledger()->available($user))->toEqualMoney('0.30');
    });
});
