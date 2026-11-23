<?php

use App\Contracts\Ledger;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Inertia\Testing\AssertableInertia as Assert;

it('shares the balance and available credit on every page', function (string $route) {
    $user = User::factory()->create();
    app(Ledger::class)->purchase($user, Money::of('1.00'), 'tok');
    app(Ledger::class)->hold(Generation::factory()->for($user)->create(), Money::of('0.25'));

    $this->actingAs($user)->get(route($route))
        ->assertInertia(fn (Assert $page) => $page
            ->where('balance.balance', '1.000000000')
            ->where('balance.available', '0.750000000'));
})->with(['generations.index', 'profile.edit']);

it('shares a null balance with guests', function () {
    $this->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('balance', null));
});
