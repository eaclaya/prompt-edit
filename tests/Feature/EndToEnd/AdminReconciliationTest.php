<?php

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\User;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\EndToEnd\Studio;
use Tests\Fixtures\PixelforgeFixtures;

beforeEach(fn () => Studio::boot('2026-11-01 09:00:00'));
afterEach(fn () => Studio::shutdown());

/**
 * The docs' GET /v1/usage day 2026-11-01 (3 generations, 41 + 0 + 3300 + 2950 tokens,
 * $0.169080) split into three generations at the docs' rates.
 */
const USAGES = [
    ['input_text_tokens' => 13, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2113], // 0.056565
    ['input_text_tokens' => 14, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 950, 'total_tokens' => 2064],  // 0.055945
    ['input_text_tokens' => 14, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1000, 'total_tokens' => 2114], // 0.056570
];

function mediumSquare(string $prompt): Generation
{
    return Studio::submit(['prompt' => $prompt, 'quality' => 'medium', 'size' => '1024x1024', 'reference_images' => []]);
}

it('reconciles the admin report against /v1/usage after a purchase and a webhook storm', function () {
    $user = Studio::register('Grace Hopper');
    test()->post(route('credits.purchase'), ['pack' => 'usd_10', 'token' => 'checkout-1'])->assertRedirect();
    test()->post(route('credits.purchase'), ['pack' => 'usd_10', 'token' => 'checkout-1'])->assertRedirect(); // double-click
    expect($user)->toHaveBalance('11.00');

    // Pixelforge's 202s, in submission order (the fake repeats only its last response).
    Studio::$pixelforge->acceptsGeneration(['id' => 'gen_storm_1'])
        ->acceptsGeneration(['id' => 'gen_storm_2'])
        ->acceptsGeneration(['id' => 'gen_storm_3'])
        ->acceptsGeneration(['id' => 'gen_storm_4']);

    // 1: its success lands while our POST is still in flight.
    $first = mediumSquare('A red fox in fresh snow, watercolour');
    Studio::duringSubmission(fn () => Studio::deliver(Studio::event($first, 'generation.succeeded', ['usage' => USAGES[0]], providerId: 'gen_storm_1')));
    Studio::submitJobs();

    // 2: processing, success, a redelivery of the success, then a late processing.
    $second = mediumSquare('A lighthouse in a storm, ink drawing');
    Studio::submitJobs();
    $processing = Studio::event($second, 'generation.processing');
    Studio::deliver($processing);
    test()->travel(20)->seconds();
    $succeeded = Studio::event($second, 'generation.succeeded', ['usage' => USAGES[1]]);
    Studio::deliver($succeeded);
    Studio::deliver($succeeded, attempt: 2);
    Studio::deliver(Studio::event($second, 'generation.processing'));

    // 3: every webhook is lost; the poller finds the result.
    $third = mediumSquare('A city of glass at night, synthwave');
    Studio::submitJobs();
    test()->travel(2)->minutes();
    Studio::$pixelforge->returnsGeneration('generation.succeeded', [
        'metadata' => ['local_id' => $third->id],
        'usage' => USAGES[2],
        'completed_at' => CarbonImmutable::now('UTC')->toIso8601ZuluString(),
    ]);
    test()->artisan('generations:reconcile')->assertSuccessful();

    // 4: fails. Pixelforge bills nothing and neither do we.
    $fourth = mediumSquare('A portrait of a robot [fail]');
    Studio::submitJobs();
    Studio::deliver(Studio::event($fourth, 'generation.failed'));

    expect(Generation::pluck('status')->all())->toEqualCanonicalizing([
        GenerationStatus::Succeeded, GenerationStatus::Succeeded, GenerationStatus::Succeeded, GenerationStatus::Failed,
    ]);
    // 11.00 − (0.07070625 + 0.06993125 + 0.0707125)
    expect($user)->toHaveBalance('10.78865');
    Studio::assertBooksBalance($user);

    // The operator's view, reconciled against the docs' usage fixture.
    $usage = collect(PixelforgeFixtures::load('usage')['data'])->keyBy('date')->all();
    Studio::$pixelforge->returnsUsage($usage);

    test()->actingAs(User::factory()->admin()->create())
        ->get(route('admin.report', ['start' => '2026-11-01', 'end' => '2026-11-02']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/Report')
            ->where('usage_error', null)
            ->where('days.0.date', '2026-11-01')
            ->where('days.0.generations', 3)
            ->where('days.0.provider_cost', '0.169080000')
            ->where('days.0.pixelforge_cost', '0.169080000')
            ->where('days.0.difference', '0.000000000')
            ->where('days.0.mismatch', false)
            ->where('days.0.credit_spend', '0.211350000')
            ->where('days.0.profit', '0.042270000')
            ->where('days.1.mismatch', false)
            ->where('totals.difference', '0.000000000')
            ->where('users.0.email', 'grace-hopper@example.com')
            ->where('users.0.credit_spend', '0.211350000'));

    Studio::$pixelforge->assertUsageRequested('2026-11-01', '2026-11-02');
});
