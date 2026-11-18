<?php

use App\Enums\LedgerType;
use App\Models\User;
use Illuminate\Auth\Events\Registered;

it('grants the welcome credit on registration', function () {
    $this->post(route('register.store'), [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'test@example.com')->firstOrFail();

    expect($user->balance_nano)->toBe(2_000_000_000)
        ->and($user->ledgerEntries()->sole()->type)->toBe(LedgerType::Grant);
});

it('grants the welcome credit only once', function () {
    $user = User::factory()->create();

    event(new Registered($user));
    event(new Registered($user));

    expect($user->fresh()->balance_nano)->toBe(2_000_000_000)
        ->and($user->ledgerEntries()->count())->toBe(1);
});
