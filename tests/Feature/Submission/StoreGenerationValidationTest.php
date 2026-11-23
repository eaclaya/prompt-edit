<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\FakeCostEstimator;
use Tests\Fakes\FakeLedger;

beforeEach(function () {
    Queue::fake();
    FakeCostEstimator::swap();
    FakeLedger::swap();
});

function validSubmission(array $overrides = []): array
{
    return array_replace([
        'prompt' => 'A lighthouse at dusk, oil painting',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ], $overrides);
}

it('sends a guest to the login page', function () {
    $this->post(route('generations.store'), validSubmission())->assertRedirect(route('login'));

    expect(Generation::count())->toBe(0);
});

it('rejects an invalid submission with field errors and creates nothing', function (array $overrides, string $field) {
    $this->actingAs(User::factory()->withBalance('5.00')->create())
        ->postJson(route('generations.store'), validSubmission($overrides))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Generation::count())->toBe(0);
})->with([
    'missing prompt' => [['prompt' => null], 'prompt'],
    'blank prompt' => [['prompt' => '   '], 'prompt'],
    'prompt over 4000 characters' => [['prompt' => str_repeat('a', 4001)], 'prompt'],
    'prompt over 4000 unicode characters' => [['prompt' => str_repeat('☕', 4001)], 'prompt'],
    'missing size' => [['size' => null], 'size'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'missing quality' => [['quality' => null], 'quality'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'reference images not a list' => [['reference_images' => 'https://example.com/a.png'], 'reference_images'],
    'more than 4 reference images' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'reference image not a url' => [['reference_images' => ['not a url']], 'reference_images.0'],
    'reference image not http(s)' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    'reference image javascript scheme' => [['reference_images' => ['javascript:alert(1)']], 'reference_images.0'],
]);

it('accepts a prompt of exactly 4000 multibyte characters and 4 http(s) reference images', function () {
    $this->actingAs(User::factory()->withBalance('5.00')->create())
        ->postJson(route('generations.store'), validSubmission([
            'prompt' => str_repeat('☕', 4000),
            'reference_images' => [
                'https://example.com/1.png',
                'http://example.com/2.png',
                'https://example.com/3.png',
                'https://example.com/4.png',
            ],
        ]))
        ->assertSessionHasNoErrors();

    expect(Generation::count())->toBe(1);
});

it('returns validation errors to an Inertia form through the session', function () {
    $this->actingAs(User::factory()->withBalance('5.00')->create())
        ->from(route('generations.create'))
        ->post(route('generations.store'), validSubmission(['size' => 'huge']))
        ->assertRedirect(route('generations.create'))
        ->assertSessionHasErrors('size');
});
