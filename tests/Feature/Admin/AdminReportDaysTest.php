<?php

use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\PixelforgeFake;

beforeEach(function () {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create();
    PixelforgeFake::fake()->returnsUsage();
});

function succeededAt(string $completedAt, string $providerCost, string $charged): Generation
{
    return Generation::factory()->succeeded()->create([
        'completed_at' => Carbon::parse($completedAt),
        'provider_cost_nanos' => Money::of($providerCost),
        'charged_nanos' => Money::of($charged),
    ]);
}

it('totals provider cost, credit spend and profit per UTC day of completion', function () {
    succeededAt('2026-11-01 00:00:00Z', '0.305510000', '0.381887500');
    succeededAt('2026-11-01 20:30:00Z', '0.100000001', '0.125000002'); // already 2 November in UTC+05:00
    succeededAt('2026-11-02 00:00:00Z', '0.040000000', '0.050000000');
    succeededAt('2026-11-02 23:59:59.999999Z', '0.000000003', '0.000000004');

    // Outside the range, on either side.
    succeededAt('2026-10-31 23:59:59.999999Z', '9.000000000', '9.000000000');
    succeededAt('2026-11-03 00:00:00Z', '9.000000000', '9.000000000');

    // Not billed: failed, or not finished yet.
    Generation::factory()->failed()->create(['completed_at' => Carbon::parse('2026-11-01 12:00:00Z')]);
    Generation::factory()->processing()->create();

    // Billing days are UTC whatever the database session's time zone is.
    DB::statement("SET TIME ZONE 'Asia/Karachi'");

    $this->actingAs($this->admin)
        ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('filters', ['start' => '2026-11-01', 'end' => '2026-11-02'])
            ->has('days', 2)
            ->where('days.0.date', '2026-11-01')
            ->where('days.0.generations', 2)
            ->where('days.0.provider_cost', '0.405510001')
            ->where('days.0.credit_spend', '0.506887502')
            ->where('days.0.profit', '0.101377501')
            ->where('days.1.date', '2026-11-02')
            ->where('days.1.generations', 2)
            ->where('days.1.provider_cost', '0.040000003')
            ->where('days.1.credit_spend', '0.050000004')
            ->where('days.1.profit', '0.010000001')
            ->where('totals.generations', 4)
            ->where('totals.provider_cost', '0.445510004')
            ->where('totals.credit_spend', '0.556887506')
            ->where('totals.profit', '0.111377502')
        );
});

it('lists every day in the range, including days without generations', function () {
    succeededAt('2026-11-02 10:00:00Z', '0.305510000', '0.381887500');

    $this->actingAs($this->admin)
        ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-03']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('days', 3)
            ->where('days.0.date', '2026-11-01')
            ->where('days.0.generations', 0)
            ->where('days.0.provider_cost', '0.000000000')
            ->where('days.0.credit_spend', '0.000000000')
            ->where('days.0.profit', '0.000000000')
            ->where('days.1.generations', 1)
            ->where('days.2.date', '2026-11-03')
        );
});

it('defaults to the last 30 UTC days, ending today', function () {
    Carbon::setTestNow('2026-11-23 22:00:00-05:00'); // already 24 November in UTC

    $this->actingAs($this->admin)
        ->get(route('admin.report'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['start' => '2026-10-26', 'end' => '2026-11-24'])
            ->has('days', 30)
        );
});

it('rejects invalid ranges', function (array $query, string $field) {
    $this->actingAs($this->admin)
        ->get(route('admin.report', $query))
        ->assertSessionHasErrors($field);
})->with([
    'not a date' => [['start' => 'yesterday', 'end' => '2026-11-02'], 'start'],
    'end before start' => [['start' => '2026-11-02', 'end' => '2026-11-01'], 'end'],
    'start after today, end defaulted' => [['start' => '2999-01-01'], 'end'],
    'longer than a year' => [['start' => '2025-01-01', 'end' => '2026-01-02'], 'end'],
]);
