<?php

use App\Contracts\Ledger;
use App\Enums\LedgerEntryType;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

it('gives a newly registered user a $1.00 welcome credit and one welcome ledger row', function () {
    $this->post(route('register.store'), [
        'name' => 'New User',
        'email' => 'new@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect();

    $user = User::where('email', 'new@example.com')->sole();

    expect($user)->toHaveBalance('1.00');
    expect($user->creditTransactions()->get())
        ->toHaveCount(1)
        ->first()->type->toBe(LedgerEntryType::Welcome);
    expect($user->creditTransactions()->sole())
        ->amount_nanos->toEqualMoney('1.00')
        ->balance_after_nanos->toEqualMoney('1.00')
        ->generation_id->toBeNull();
});

it('uses the configured welcome amount', function () {
    config(['pixelforge.welcome_credit' => '2.50']);
    $user = User::factory()->create();

    app(Ledger::class)->grantWelcome($user);

    expect($user)->toHaveBalance('2.50');
});

it('grants the welcome credit only once per user', function () {
    $user = User::factory()->create();

    event(new Registered($user));
    event(new Registered($user));
    app(Ledger::class)->grantWelcome($user);

    expect($user)->toHaveBalance('1.00');
    expect($user->creditTransactions()->count())->toBe(1);
});
