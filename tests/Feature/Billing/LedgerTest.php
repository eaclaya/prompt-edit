<?php

use App\Billing\InsufficientCredits;
use App\Billing\Ledger;
use App\Enums\LedgerType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;

beforeEach(function () {
    $this->ledger = app(Ledger::class);
    $this->user = User::factory()->create();
});

function balanceOf(User $user): int
{
    return $user->fresh()->balance_nano;
}

it('adds grants and purchases to the balance', function () {
    $this->ledger->grant($this->user, 2_000_000_000, 'welcome:1', 'Welcome credit');
    $this->ledger->purchase($this->user, 5_000_000_000, 'buy-abc');

    expect(balanceOf($this->user))->toBe(7_000_000_000);
});

it('records a purchase only once per idempotency key', function () {
    $first = $this->ledger->purchase($this->user, 5_000_000_000, 'buy-abc');
    $second = $this->ledger->purchase($this->user, 5_000_000_000, 'buy-abc');

    expect($second->id)->toBe($first->id)
        ->and($first->wasRecentlyCreated)->toBeTrue()
        ->and($second->wasRecentlyCreated)->toBeFalse()
        ->and(balanceOf($this->user))->toBe(5_000_000_000)
        ->and(LedgerEntry::count())->toBe(1);
});

it('holds the maximum estimated price', function () {
    $this->ledger->grant($this->user, 1_000_000_000, 'g1', 'Grant');
    $generation = Generation::factory()->for($this->user)->create(['est_price_max_nano' => 86_431_250]);

    $hold = $this->ledger->hold($generation);

    expect($hold->amount_nano)->toBe(-86_431_250)
        ->and($hold->type)->toBe(LedgerType::Hold)
        ->and(balanceOf($this->user))->toBe(1_000_000_000 - 86_431_250);
});

it('refuses a hold the user cannot afford', function () {
    $this->ledger->grant($this->user, 50_000_000, 'g1', 'Grant');
    $generation = Generation::factory()->for($this->user)->create(['est_price_max_nano' => 86_431_250]);

    expect(fn () => $this->ledger->hold($generation))->toThrow(InsufficientCredits::class);
    expect(balanceOf($this->user))->toBe(50_000_000)
        ->and(LedgerEntry::where('type', LedgerType::Hold)->count())->toBe(0);
});

it('settles a success: releases the hold and charges the actual price', function () {
    $this->ledger->grant($this->user, 1_000_000_000, 'g1', 'Grant');
    $generation = Generation::factory()->for($this->user)->create(['est_price_max_nano' => 86_431_250]);
    $this->ledger->hold($generation);

    $completedAt = Carbon::parse('2026-11-18T23:59:58Z');
    $this->ledger->release($generation);
    $charge = $this->ledger->charge($generation, 71_000_000, 56_800_000, $completedAt);

    expect(balanceOf($this->user))->toBe(1_000_000_000 - 71_000_000)
        ->and($charge->amount_nano)->toBe(-71_000_000)
        ->and($charge->provider_cost_nano)->toBe(56_800_000)
        ->and($charge->occurred_at->toIso8601ZuluString())->toBe('2026-11-18T23:59:58Z');
});

it('settles a failure for free', function () {
    $this->ledger->grant($this->user, 1_000_000_000, 'g1', 'Grant');
    $generation = Generation::factory()->for($this->user)->create();
    $this->ledger->hold($generation);

    $this->ledger->release($generation);

    expect(balanceOf($this->user))->toBe(1_000_000_000);
});

it('never charges, holds or releases a generation twice', function () {
    $this->ledger->grant($this->user, 1_000_000_000, 'g1', 'Grant');
    $generation = Generation::factory()->for($this->user)->create();

    $this->ledger->hold($generation);
    $this->ledger->hold($generation);
    $this->ledger->release($generation);
    $this->ledger->release($generation);
    $this->ledger->charge($generation, 71_000_000, 56_800_000, now());
    $this->ledger->charge($generation, 71_000_000, 56_800_000, now());

    expect(LedgerEntry::where('generation_id', $generation->id)->count())->toBe(3)
        ->and(balanceOf($this->user))->toBe(1_000_000_000 - 71_000_000);
});

it('does nothing when releasing a generation that was never held', function () {
    $generation = Generation::factory()->for($this->user)->create();

    expect($this->ledger->release($generation))->toBeNull()
        ->and(LedgerEntry::count())->toBe(0);
});

it('backs up the existence check with the unique index', function () {
    $generation = Generation::factory()->for($this->user)->create();
    LedgerEntry::create([
        'user_id' => $this->user->id,
        'generation_id' => $generation->id,
        'type' => LedgerType::Charge,
        'amount_nano' => -1,
        'occurred_at' => now(),
    ]);

    expect(fn () => LedgerEntry::create([
        'user_id' => $this->user->id,
        'generation_id' => $generation->id,
        'type' => LedgerType::Charge,
        'amount_nano' => -1,
        'occurred_at' => now(),
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('keeps every balance equal to the sum of its entries', function () {
    $other = User::factory()->create();
    $this->ledger->grant($this->user, 2_000_000_000, 'welcome:a', 'Welcome credit');
    $this->ledger->grant($other, 2_000_000_000, 'welcome:b', 'Welcome credit');
    $this->ledger->purchase($this->user, 5_000_000_000, 'buy-1');
    $this->ledger->purchase($this->user, 5_000_000_000, 'buy-1');

    foreach (range(1, 6) as $i) {
        $generation = Generation::factory()->for($i % 2 ? $this->user : $other)->create();
        $this->ledger->hold($generation);
        $this->ledger->release($generation);

        if ($i % 3 !== 0) {
            $this->ledger->charge($generation, 70_000_000 + $i, 56_000_000 + $i, now());
            $this->ledger->charge($generation, 70_000_000 + $i, 56_000_000 + $i, now());
        }
    }

    expect($this->ledger->discrepancies())->toBeEmpty()
        ->and(balanceOf($this->user))->toBe((int) LedgerEntry::where('user_id', $this->user->id)->sum('amount_nano'));

    $this->artisan('ledger:verify')->assertSuccessful();
});

it('reports a balance that drifted from its entries', function () {
    $this->ledger->grant($this->user, 2_000_000_000, 'welcome:a', 'Welcome credit');
    $this->user->forceFill(['balance_nano' => 1])->save();

    expect($this->ledger->discrepancies())->toHaveCount(1);
    $this->artisan('ledger:verify')->assertFailed();
});

it('keeps financial records when the user deletes their account', function () {
    $this->ledger->grant($this->user, 1_000_000_000, 'g1', 'Grant');
    $generation = Generation::factory()->for($this->user)->create();
    $this->ledger->hold($generation);

    $this->user->delete();

    expect(LedgerEntry::count())->toBe(2)
        ->and(LedgerEntry::whereNull('user_id')->count())->toBe(2)
        ->and($generation->fresh()->user_id)->toBeNull();
});
