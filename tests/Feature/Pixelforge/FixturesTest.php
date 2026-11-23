<?php

use Tests\Fixtures\PixelforgeFixtures;

it('loads every docs payload', function (string $name) {
    expect(PixelforgeFixtures::load($name))->toBeArray()->not->toBeEmpty();
})->with(fn () => PixelforgeFixtures::names());

it('lists generations, events, pricing, usage and every documented error', function () {
    expect(PixelforgeFixtures::names())->toContain(
        'generation.queued', 'generation.processing', 'generation.succeeded', 'generation.failed',
        'generations.list', 'pricing', 'usage',
        'event.generation.processing', 'event.generation.succeeded', 'event.generation.failed', 'event.ping',
        'errors/validation_failed', 'errors/invalid_api_key', 'errors/content_policy_violation', 'errors/not_found',
        'errors/idempotency_key_reused', 'errors/rate_limit_exceeded', 'errors/concurrency_limit_exceeded',
        'errors/daily_limit_exceeded', 'errors/overloaded', 'errors/date_range_too_long',
    );
});

it('returns the documented succeeded generation', function () {
    $generation = PixelforgeFixtures::load('generation.succeeded');

    expect($generation['id'])->toBe('gen_01JC2Z8Q4N6V0M3H7X5T9R2B1K')
        ->and($generation['usage'])->toBe([
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ]);
});

it('merges overrides recursively and replaces lists', function () {
    $generation = PixelforgeFixtures::load('generation.queued', [
        'metadata' => ['local_id' => '01KAB'],
        'reference_images' => [],
    ]);

    expect($generation['metadata'])->toBe(['order_id' => '1042', 'local_id' => '01KAB'])
        ->and($generation['reference_images'])->toBe([])
        ->and($generation['status'])->toBe('queued');
});

it('builds a webhook event around any generation', function () {
    $event = PixelforgeFixtures::event('generation.succeeded', ['metadata' => ['local_id' => '01KAB']]);

    expect($event['type'])->toBe('generation.succeeded')
        ->and($event['id'])->toStartWith('evt_')
        ->and($event['data']['object']['status'])->toBe('succeeded')
        ->and($event['data']['object']['metadata']['local_id'])->toBe('01KAB')
        ->and(PixelforgeFixtures::event('generation.succeeded')['id'])->not->toBe($event['id']);
});
