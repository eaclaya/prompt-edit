<?php

use App\Models\Generation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    $this->user = User::factory()->create();
});

it('renders the history page', function () {
    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('generations/Index')
            ->has('generations.data', 0)
            ->has('generations.meta')
            ->has('generations.links'));
});

it('lists only the signed-in user\'s generations', function () {
    $mine = Generation::factory()->for($this->user)->create();
    Generation::factory()->count(2)->create();

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 1)
            ->where('generations.data.0.id', $mine->id)
            ->where('generations.meta.total', 1));
});

it('lists newest first', function () {
    $old = Generation::factory()->for($this->user)->create(['created_at' => now()->subHour()]);
    $new = Generation::factory()->for($this->user)->create(['created_at' => now()]);

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.id', $new->id)
            ->where('generations.data.1.id', $old->id));
});

it('paginates twelve per page', function () {
    Generation::factory()->for($this->user)->count(13)->create();

    $this->actingAs($this->user)
        ->get(route('generations.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data', 1)
            ->where('generations.meta.current_page', 2)
            ->where('generations.meta.last_page', 2)
            ->where('generations.meta.per_page', 12)
            ->where('generations.meta.total', 13));
});

it('sends a succeeded generation with its estimate, actual cost and live image', function () {
    $generation = Generation::factory()->for($this->user)->succeeded()->create();

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('generations.data.0', fn (Assert $card) => $card
                ->where('id', $generation->id)
                ->where('prompt', $generation->prompt)
                ->where('size', '1536x1024')
                ->where('quality', 'high')
                ->where('reference_images', ['https://example.com/style.png'])
                ->where('status', 'succeeded')
                ->where('status_label', 'Succeeded')
                ->where('is_final', true)
                ->where('estimate', [
                    'min' => '0.347750000',
                    'expected' => '0.402450000',
                    'max' => '0.457150000',
                ])
                ->where('charged', '0.381887500')
                ->where('image_url', $generation->image_url)
                ->where('image_expired', false)
                ->where('error', null)
                ->where('created_at', $generation->created_at?->toJSON())
                ->where('completed_at', $generation->completed_at?->toJSON())));
});

it('shows a pending generation as queued, not final, with no actual cost yet', function () {
    Generation::factory()->for($this->user)->pending()->create();

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.status', 'pending')
            ->where('generations.data.0.status_label', 'Queued')
            ->where('generations.data.0.is_final', false)
            ->where('generations.data.0.charged', null)
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.completed_at', null));
});

it('sends the status of every generation state', function (string $state, string $status, string $label, bool $final) {
    Generation::factory()->for($this->user)->{$state}()->create();

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.status', $status)
            ->where('generations.data.0.status_label', $label)
            ->where('generations.data.0.is_final', $final));
})->with([
    'pending' => ['pending', 'pending', 'Queued', false],
    'queued' => ['queued', 'queued', 'Queued', false],
    'processing' => ['processing', 'processing', 'Processing', false],
    'succeeded' => ['succeeded', 'succeeded', 'Succeeded', true],
    'failed' => ['failed', 'failed', 'Failed', true],
]);

it('sends a failed generation with its error and a zero charge', function () {
    Generation::factory()->for($this->user)->failed('content_policy_violation', 'Blocked by the content policy.')->create();

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.charged', '0.000000000')
            ->where('generations.data.0.image_url', null)
            ->where('generations.data.0.error', [
                'code' => 'content_policy_violation',
                'message' => 'Blocked by the content policy.',
            ]));
});

it('flags an expired image and withholds its url', function () {
    Generation::factory()->for($this->user)->succeeded()->imageExpired()->create();

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image_expired', true)
            ->where('generations.data.0.image_url', null));
});

it('treats an image as expired from the exact expiry instant', function () {
    $this->freezeTime();
    Generation::factory()->for($this->user)->succeeded()->create(['image_url_expires_at' => now()]);

    $this->actingAs($this->user)
        ->get(route('generations.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('generations.data.0.image_expired', true));
});
