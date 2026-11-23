<?php

use App\Contracts\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Inertia\Testing\AssertableInertia as Assert;

describe('POST /credits/purchase', function () {
    it('adds the pack amount to the balance and redirects back', function (string $pack, string $amount) {
        $user = User::factory()->create();

        $this->actingAs($user)->from(route('credits.index'))
            ->post(route('credits.purchase'), ['pack' => $pack, 'token' => 'f3b6c6c8-1d0e-4c55-9d56-2a8f1d1e1a01'])
            ->assertRedirect(route('credits.index'))
            ->assertSessionHasNoErrors();

        expect($user)->toHaveBalance($amount);
        expect($user->creditTransactions()->sole())
            ->type->toBe(LedgerEntryType::Purchase)
            ->idempotency_key->toBe('f3b6c6c8-1d0e-4c55-9d56-2a8f1d1e1a01');
    })->with([
        'usd_5' => ['usd_5', '5.00'],
        'usd_10' => ['usd_10', '10.00'],
        'usd_25' => ['usd_25', '25.00'],
    ]);

    it('credits a double-clicked purchase once', function () {
        $user = User::factory()->create();
        $body = ['pack' => 'usd_10', 'token' => 'a0d1b2c3-0000-4000-8000-000000000001'];

        $this->actingAs($user)->post(route('credits.purchase'), $body)->assertRedirect();
        $this->actingAs($user)->post(route('credits.purchase'), $body)->assertRedirect();

        expect($user)->toHaveBalance('10.00')
            ->and($user->creditTransactions()->count())->toBe(1);
    });

    it('returns 422 for an unknown pack and credits nothing', function () {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('credits.purchase'), ['pack' => 'usd_1000', 'token' => 'a0d1b2c3-0000-4000-8000-000000000002'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pack');

        expect($user)->toHaveBalance('0');
    });

    it('requires a token', function (mixed $token) {
        $this->actingAs(User::factory()->create())
            ->postJson(route('credits.purchase'), ['pack' => 'usd_5', 'token' => $token])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');
    })->with([null, '', str_repeat('x', 101)]);

    it('sends Inertia form errors back through the session', function () {
        $this->actingAs(User::factory()->create())->from(route('credits.index'))
            ->post(route('credits.purchase'), ['pack' => 'nope', 'token' => 'abc'])
            ->assertRedirect(route('credits.index'))
            ->assertSessionHasErrors('pack');
    });
});

describe('GET /credits', function () {
    it('shows the balance, available credit and the packs', function () {
        $user = User::factory()->create();
        app(Ledger::class)->purchase($user, Money::of('10.00'), 'tok');
        app(Ledger::class)->hold(Generation::factory()->for($user)->create(), Money::of('0.40'));

        $this->actingAs($user)->get(route('credits.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('credits/Index')
                ->where('balance', '10.000000000')
                ->where('available', '9.600000000')
                ->where('packs', [
                    ['id' => 'usd_5', 'amount' => '5.000000000', 'label' => '$5'],
                    ['id' => 'usd_10', 'amount' => '10.000000000', 'label' => '$10'],
                    ['id' => 'usd_25', 'amount' => '25.000000000', 'label' => '$25'],
                ]));
    });

    it('lists only the user\'s ledger, newest first', function () {
        $user = User::factory()->create();
        $ledger = app(Ledger::class);
        $ledger->grantWelcome($user);
        $ledger->purchase($user, Money::of('5.00'), 'tok');
        $generation = Generation::factory()->for($user)->create();
        $ledger->hold($generation, Money::of('0.40'));
        $ledger->settle($generation, Money::of('0.3818875'));
        $ledger->purchase(User::factory()->create(), Money::of('25.00'), 'other');

        $this->actingAs($user)->get(route('credits.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 5)
                ->where('transactions.data.0.type', 'charge')
                ->where('transactions.data.0.amount', '-0.381887500')
                ->where('transactions.data.0.balance_after', '5.618112500')
                ->where('transactions.data.0.generation_id', $generation->id)
                ->where('transactions.data.0.description', 'Generation charge')
                ->where('transactions.data.1.type', 'hold_release')
                ->where('transactions.data.2.type', 'hold')
                ->where('transactions.data.2.description', 'Hold for generation')
                ->where('transactions.data.3.type', 'purchase')
                ->where('transactions.data.3.description', 'Purchased credits')
                ->where('transactions.data.4.type', 'welcome')
                ->where('transactions.data.4.description', 'Welcome credit')
                ->where('transactions.data.4.generation_id', null)
                ->has('transactions.data.0.id')
                ->has('transactions.data.0.created_at')
                ->has('transactions.meta.total')
                ->has('transactions.links.next'));
    });

    it('paginates the ledger', function () {
        $user = User::factory()->create();
        foreach (range(1, 30) as $i) {
            app(Ledger::class)->purchase($user, Money::of('1.00'), "tok-{$i}");
        }

        $this->actingAs($user)->get(route('credits.index', ['page' => 2]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('transactions.data', 5)
                ->where('transactions.meta.current_page', 2)
                ->where('transactions.meta.total', 30)
                ->where('transactions.data.4.balance_after', '1.000000000'));
    });
});
