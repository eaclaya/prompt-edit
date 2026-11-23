<?php

use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fakes\PixelforgeFake;
use Tests\Fixtures\PixelforgeFixtures;

beforeEach(function () {
    $this->withoutVite();
    $this->admin = User::factory()->admin()->create();
});

function billedOn(string $completedAt, string $providerCost): Generation
{
    return Generation::factory()->succeeded()->create([
        'completed_at' => Carbon::parse($completedAt),
        'provider_cost_nanos' => Money::of($providerCost),
        'charged_nanos' => Money::of($providerCost)->multiplyRatio(125, 100),
    ]);
}

/** The docs' GET /v1/usage example (2026-11-01..02), keyed by date for PixelforgeFake. */
function usageFixtureDays(): array
{
    return collect(PixelforgeFixtures::load('usage')['data'])->keyBy('date')->all();
}

it('compares the /v1/usage fixture to our totals and flags the days that differ', function () {
    $pixelforge = PixelforgeFake::fake()->returnsUsage(usageFixtureDays());

    // 1 November: our three generations add up to the fixture's 0.169080000.
    billedOn('2026-11-01 01:00:00Z', '0.056360000');
    billedOn('2026-11-01 12:00:00Z', '0.056360000');
    billedOn('2026-11-01 23:00:00Z', '0.056360000');
    // 2 November: Pixelforge billed nothing, we recorded a cost.
    billedOn('2026-11-02 08:00:00Z', '0.000000001');

    $this->actingAs($this->admin)
        ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('usage_error', null)
            ->where('days.0.date', '2026-11-01')
            ->where('days.0.provider_cost', '0.169080000')
            ->where('days.0.pixelforge_cost', '0.169080000')
            ->where('days.0.difference', '0.000000000')
            ->where('days.0.mismatch', false)
            ->where('days.1.date', '2026-11-02')
            ->where('days.1.provider_cost', '0.000000001')
            ->where('days.1.pixelforge_cost', '0.000000000')
            ->where('days.1.difference', '0.000000001')
            ->where('days.1.mismatch', true)
            ->where('totals.pixelforge_cost', '0.169080000')
            ->where('totals.difference', '0.000000001')
        );

    $pixelforge->assertUsageRequested('2026-11-01', '2026-11-02');
});

it('flags a day Pixelforge billed more than we recorded, with a negative difference', function () {
    PixelforgeFake::fake()->returnsUsage(['2026-11-01' => ['generations' => 1, 'cost_usd' => '0.305510000']]);

    $this->actingAs($this->admin)
        ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-01']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('days.0.provider_cost', '0.000000000')
            ->where('days.0.pixelforge_cost', '0.305510000')
            ->where('days.0.difference', '-0.305510000')
            ->where('days.0.mismatch', true)
        );
});

it('still shows our own numbers when /v1/usage fails', function () {
    PixelforgeFake::fake()->failsWith('overloaded', endpoint: 'usage');
    billedOn('2026-11-01 08:00:00Z', '0.305510000');

    $this->actingAs($this->admin)
        ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-01']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('usage_error', fn (?string $error) => is_string($error) && $error !== '')
            ->where('days.0.provider_cost', '0.305510000')
            ->where('days.0.pixelforge_cost', null)
            ->where('days.0.difference', null)
            ->where('days.0.mismatch', false)
            ->where('totals.pixelforge_cost', null)
            ->where('totals.difference', null)
        );
});
