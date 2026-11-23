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

describe('settle', function () {
    it('charges the actual amount and releases the hold in one go', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);
        ledger()->hold($generation, Money::of('0.40'));

        ledger()->settle($generation, Money::of('0.3818875'));

        expect($user)->toHaveBalance('0.6181125')
            ->and(ledger()->available($user))->toEqualMoney('0.6181125')
            ->and(entryTypes($generation))->toBe([LedgerEntryType::Hold, LedgerEntryType::HoldRelease, LedgerEntryType::Charge]);

        $release = CreditTransaction::where('generation_id', $generation->id)->where('type', LedgerEntryType::HoldRelease)->sole();
        $charge = CreditTransaction::where('generation_id', $generation->id)->where('type', LedgerEntryType::Charge)->sole();
        expect($release->amount_nanos)->toEqualMoney('0.40')
            ->and($charge->amount_nanos)->toEqualMoney('-0.3818875')
            ->and($charge->balance_after_nanos)->toEqualMoney('0.6181125');
    });

    it('rejects a negative charge and writes nothing', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);
        ledger()->hold($generation, Money::of('0.40'));

        expect(fn () => ledger()->settle($generation, Money::of('-0.10')))->toThrow(InvalidArgumentException::class);

        expect(entryTypes($generation))->toBe([LedgerEntryType::Hold])
            ->and(ledger()->available($user))->toEqualMoney('0.60');
    });

    it('records one charge when the same generation is settled twice', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);
        ledger()->hold($generation, Money::of('0.40'));

        ledger()->settle($generation, Money::of('0.30'));
        ledger()->settle($generation, Money::of('0.30'));
        ledger()->settle($generation, Money::of('0.35'));

        expect($user)->toHaveBalance('0.70')
            ->and(entryTypes($generation))->toBe([LedgerEntryType::Hold, LedgerEntryType::HoldRelease, LedgerEntryType::Charge]);
    });

    it('is a no-op after the hold was released', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);
        ledger()->hold($generation, Money::of('0.40'));
        ledger()->release($generation);

        ledger()->settle($generation, Money::of('0.30'));

        expect($user)->toHaveBalance('1.00')
            ->and(entryTypes($generation))->toBe([LedgerEntryType::Hold, LedgerEntryType::HoldRelease]);
    });

    it('charges the true amount when it exceeds the hold', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);
        ledger()->hold($generation, Money::of('0.40'));

        ledger()->settle($generation, Money::of('0.45'));

        expect($user)->toHaveBalance('0.55')
            ->and(ledger()->available($user))->toEqualMoney('0.55');
    });

    it('charges without a release row when no hold was placed', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);

        ledger()->settle($generation, Money::of('0.30'));

        expect($user)->toHaveBalance('0.70')
            ->and(entryTypes($generation))->toBe([LedgerEntryType::Charge]);
    });
});

describe('release', function () {
    it('returns the hold and charges nothing', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);
        ledger()->hold($generation, Money::of('0.40'));

        ledger()->release($generation);

        expect($user)->toHaveBalance('1.00')
            ->and(ledger()->available($user))->toEqualMoney('1.00')
            ->and(entryTypes($generation))->toBe([LedgerEntryType::Hold, LedgerEntryType::HoldRelease]);
        expect(CreditTransaction::where('generation_id', $generation->id)->where('type', LedgerEntryType::HoldRelease)->sole()->amount_nanos)
            ->toEqualMoney('0.40');
    });

    it('is a no-op the second time and after a settle', function () {
        $user = fundedUser('1.00');
        $released = generationFor($user);
        $settled = generationFor($user);
        ledger()->hold($released, Money::of('0.40'));
        ledger()->hold($settled, Money::of('0.40'));

        ledger()->release($released);
        ledger()->release($released);
        ledger()->settle($settled, Money::of('0.30'));
        ledger()->release($settled);

        expect($user)->toHaveBalance('0.70')
            ->and(ledger()->available($user))->toEqualMoney('0.70')
            ->and(entryTypes($released))->toBe([LedgerEntryType::Hold, LedgerEntryType::HoldRelease])
            ->and(entryTypes($settled))->toBe([LedgerEntryType::Hold, LedgerEntryType::HoldRelease, LedgerEntryType::Charge]);
    });

    it('does nothing for a generation that was never held', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);

        ledger()->release($generation);

        expect(entryTypes($generation))->toBe([]);
    });
});

describe('database guarantees', function () {
    it('rejects a second charge row for the same generation at the database level', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);
        ledger()->settle($generation, Money::of('0.30'));

        expect(fn () => CreditTransaction::create([
            'user_id' => $user->id,
            'generation_id' => $generation->id,
            'type' => LedgerEntryType::Charge,
            'amount_nanos' => Money::of('-0.30'),
            'balance_after_nanos' => Money::of('0.40'),
        ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
    });

    it('leaves a charged generation alone when its owner account is gone', function () {
        $user = fundedUser('1.00');
        $generation = generationFor($user);
        ledger()->hold($generation, Money::of('0.40'));
        $user->delete();
        $generation->refresh();

        ledger()->settle($generation, Money::of('0.30'));
        ledger()->release($generation);

        expect(entryTypes($generation))->toBe([LedgerEntryType::Hold]);
    });
});
