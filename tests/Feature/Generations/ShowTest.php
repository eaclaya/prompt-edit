<?php

use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
});

it('renders the detail page for the user\'s own generation', function () {
    $generation = Generation::factory()->for($this->user)->processing()->create();

    $this->actingAs($this->user)
        ->get(route('generations.show', $generation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Show')
            ->where('generation.id', $generation->id)
            ->where('generation.status', 'processing')
            ->where('generation.started_at', $generation->started_at?->toJSON()));
});

it('returns 404 for another user\'s generation', function () {
    $theirs = Generation::factory()->succeeded()->create();

    $this->actingAs($this->user)
        ->get(route('generations.show', $theirs))
        ->assertNotFound();
});

it('returns 404 for a generation whose owner was deleted', function () {
    $orphan = Generation::factory()->create(['user_id' => null]);

    $this->actingAs($this->user)
        ->get(route('generations.show', $orphan))
        ->assertNotFound();
});

it('sends the usage, rates and cost breakdown of a succeeded generation', function () {
    config(['pixelforge.markup_bps' => 2500]);
    $generation = Generation::factory()->for($this->user)->succeeded()->create();

    $this->actingAs($this->user)
        ->get(route('generations.show', $generation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generation.usage', [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
                'total_tokens' => 10411,
            ])
            ->where('generation.rates', [
                'input_text_tokens' => '5.000000000',
                'input_image_tokens' => '10.000000000',
                'output_tokens' => '40.000000000',
                'reasoning_tokens' => '12.500000000',
            ])
            ->where('generation.breakdown', [
                'lines' => [
                    ['token_type' => 'input_text_tokens', 'tokens' => 12, 'rate' => '5.000000000', 'cost' => '0.000060000'],
                    ['token_type' => 'input_image_tokens', 'tokens' => 765, 'rate' => '10.000000000', 'cost' => '0.007650000'],
                    ['token_type' => 'output_tokens', 'tokens' => 6450, 'rate' => '40.000000000', 'cost' => '0.258000000'],
                    ['token_type' => 'reasoning_tokens', 'tokens' => 3184, 'rate' => '12.500000000', 'cost' => '0.039800000'],
                ],
                'provider_cost' => '0.305510000',
                'markup_bps' => 2500,
                'charged' => '0.381887500',
            ])
            ->where('generation.image_url_expires_at', $generation->image_url_expires_at?->toJSON())
            ->where('generation.image_expired', false));
});

it('sends no breakdown before the generation succeeds', function (string $state) {
    $generation = Generation::factory()->for($this->user)->{$state}()->create();

    $this->actingAs($this->user)
        ->get(route('generations.show', $generation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generation.usage', null)
            ->where('generation.breakdown', null));
})->with(['pending', 'queued', 'processing', 'failed']);

it('sends rates once the generation is accepted', function () {
    $queued = Generation::factory()->for($this->user)->queued()->create();
    $pending = Generation::factory()->for($this->user)->pending()->create();

    $this->actingAs($this->user)
        ->get(route('generations.show', $queued))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generation.rates.output_tokens', '40.000000000'));

    $this->actingAs($this->user)
        ->get(route('generations.show', $pending))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generation.rates', null));
});

it('flags an expired image on the detail page', function () {
    $generation = Generation::factory()->for($this->user)->succeeded()->imageExpired()->create();

    $this->actingAs($this->user)
        ->get(route('generations.show', $generation))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generation.image_expired', true)
            ->where('generation.image_url', null)
            ->where('generation.breakdown.charged', '0.381887500'));
});
