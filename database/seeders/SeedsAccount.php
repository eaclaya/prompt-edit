<?php

namespace Database\Seeders;

use App\Contracts\Ledger;
use App\Models\User;
use RuntimeException;

/** Creates (or updates) a verified account from config('seed.<key>'), with its welcome credit. */
trait SeedsAccount
{
    protected function seedAccount(string $key, bool $admin): User
    {
        /** @var array{name: string, email: string, password: ?string} $account */
        $account = config("seed.{$key}");

        if (blank($account['password'])) {
            throw new RuntimeException('Set SEED_'.strtoupper($key).'_PASSWORD before seeding the '.$key.' account.');
        }

        $user = User::firstOrNew(['email' => $account['email']]);
        $user->forceFill([
            'name' => $account['name'],
            'password' => $account['password'],
            'is_admin' => $admin,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        app(Ledger::class)->grantWelcome($user);

        return $user;
    }
}
