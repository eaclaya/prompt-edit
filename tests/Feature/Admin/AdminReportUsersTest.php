<?php

use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\PixelforgeFake;

beforeEach(function () {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create();
    PixelforgeFake::fake()->returnsUsage();
});

function billedFor(?User $user, string $completedAt, string $providerCost, string $charged): Generation
{
    return Generation::factory()->succeeded()->create([
        'user_id' => $user?->id,
        'completed_at' => Carbon::parse($completedAt),
        'provider_cost_nanos' => Money::of($providerCost),
        'charged_nanos' => Money::of($charged),
    ]);
}

it('totals each user over the range, biggest spender first', function () {
    $bob = User::factory()->create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $ada = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
    $idle = User::factory()->create(['name' => 'Idle']);

    billedFor($bob, '2026-11-01 08:00:00Z', '0.305510000', '0.381887500');
    billedFor($ada, '2026-11-01 09:00:00Z', '0.305510000', '0.381887500');
    billedFor($ada, '2026-11-02 23:59:59Z', '0.305510000', '0.381887500');

    // Outside the range or not billed.
    billedFor($bob, '2026-11-03 00:00:00Z', '5.000000000', '6.250000000');
    billedFor($idle, '2026-10-31 23:59:59Z', '5.000000000', '6.250000000');
    Generation::factory()->failed()->for($idle)->create(['completed_at' => Carbon::parse('2026-11-01 12:00:00Z')]);

    $this->actingAs($this->admin)
        ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users', 2)
            ->where('users.0', [
                'user_id' => $ada->id,
                'name' => 'Ada',
                'email' => 'ada@example.com',
                'generations' => 2,
                'provider_cost' => '0.611020000',
                'credit_spend' => '0.763775000',
                'profit' => '0.152755000',
            ])
            ->where('users.1.user_id', $bob->id)
            ->where('users.1.generations', 1)
            ->where('users.1.provider_cost', '0.305510000')
            ->where('users.1.credit_spend', '0.381887500')
            ->where('users.1.profit', '0.076377500')
        );
});

it('keeps the spend of deleted accounts as one row', function () {
    billedFor(null, '2026-11-01 08:00:00Z', '0.305510000', '0.381887500');
    billedFor(null, '2026-11-01 09:00:00Z', '0.100000000', '0.125000000');

    $this->actingAs($this->admin)
        ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-01']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('users', 1)
            ->where('users.0.user_id', null)
            ->where('users.0.name', 'Deleted user')
            ->where('users.0.email', '')
            ->where('users.0.generations', 2)
            ->where('users.0.credit_spend', '0.506887500')
        );
});
