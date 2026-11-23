<?php

use App\Contracts\Ledger;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;
use Tests\Fakes\PixelforgeFake;

beforeEach(function () {
    config([
        'seed.demo' => ['name' => 'Demo User', 'email' => 'demo@example.test', 'password' => 'demo-secret-1'],
        'seed.admin' => ['name' => 'Operator', 'email' => 'admin@example.test', 'password' => 'admin-secret-1'],
    ]);
});

it('seeds a verified demo user with the welcome credit', function () {
    $this->seed(DatabaseSeeder::class);

    $demo = User::where('email', 'demo@example.test')->sole();
    expect($demo->is_admin)->toBeFalse()
        ->and($demo->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('demo-secret-1', $demo->password))->toBeTrue()
        ->and($demo)->toHaveBalance('1.00')
        ->and(app(Ledger::class)->available($demo))->toEqualMoney('1.00');
});

it('seeds a verified admin who can open the report', function () {
    $this->seed(DatabaseSeeder::class);

    $admin = User::where('email', 'admin@example.test')->sole();
    expect($admin->is_admin)->toBeTrue()
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('admin-secret-1', $admin->password))->toBeTrue();

    PixelforgeFake::fake()->returnsUsage();
    $this->withoutVite()->actingAs($admin)->get(route('admin.report'))->assertOk();
});

it('can be run twice without duplicating users or credit', function () {
    $this->seed(DatabaseSeeder::class);
    $this->seed(DatabaseSeeder::class);

    expect(User::count())->toBe(2)
        ->and(User::where('email', 'demo@example.test')->sole())->toHaveBalance('1.00');
});

it('refuses to seed an account without a password', function () {
    config(['seed.admin.password' => null]);

    $this->seed(DatabaseSeeder::class);
})->throws(RuntimeException::class, 'SEED_ADMIN_PASSWORD');
