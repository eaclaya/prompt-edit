<?php

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\SignedWebhook;
use Tests\Fixtures\PixelforgeFixtures;

beforeEach(fn () => Queue::fake());

it('rejects a delivery with a missing, malformed or wrong signature', function (?string $signature) {
    $webhook = signedWebhook(PixelforgeFixtures::event('generation.processing'))->withSignature($signature);

    postWebhook($webhook)->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
})->with([
    'missing' => [null],
    'empty' => [''],
    'no v1' => ['t=1700000000'],
    'no timestamp' => ['v1=abc'],
    'garbage' => ['not-a-signature'],
    'non-numeric timestamp' => ['t=soon,v1=abc'],
    'wrong hex' => [fn () => 't='.now()->getTimestamp().',v1='.str_repeat('0', 64)],
]);

it('rejects a delivery signed with another secret', function () {
    postWebhook(PixelforgeFixtures::event('generation.processing'), secret: 'whsec_someone_else')
        ->assertStatus(400);
});

it('rejects a body that was changed after signing', function () {
    $webhook = signedWebhook(PixelforgeFixtures::event('generation.processing'));
    $tampered = new SignedWebhook(str_replace('processing', 'succeeded', $webhook->body), $webhook->headers, $webhook->timestamp);

    postWebhook($tampered)->assertStatus(400);
});

it('rejects a timestamp more than 300 seconds off', function (int $offset) {
    postWebhook(PixelforgeFixtures::event('generation.processing'), timestamp: now()->getTimestamp() + $offset)
        ->assertStatus(400);
})->with(['too old' => [-301], 'from the future' => [301]]);

it('accepts a timestamp within 300 seconds', function (int $offset) {
    postWebhook(PixelforgeFixtures::event('generation.processing'), timestamp: now()->getTimestamp() + $offset)
        ->assertOk();
})->with(['300 s old' => [-300], '300 s ahead' => [300], 'now' => [0]]);

it('accepts any matching v1 when several are sent (secret rotation)', function () {
    $webhook = signedWebhook(PixelforgeFixtures::event('generation.processing'));
    [$t, $v1] = explode(',', $webhook->headers['Pixelforge-Signature']);

    postWebhook($webhook->withSignature("{$t},v1=".str_repeat('a', 64).",{$v1}"))->assertOk();
});

it('accepts a valid signature without a CSRF token or session', function () {
    postWebhook(PixelforgeFixtures::event('generation.processing'))->assertOk();

    Queue::assertPushed(ProcessWebhookEvent::class);
});

it('fails closed when no webhook secret is configured', function () {
    $payload = PixelforgeFixtures::event('generation.processing');
    $webhook = signedWebhook($payload, secret: '');
    config(['pixelforge.webhook_secret' => '']);

    postWebhook($webhook)->assertStatus(400);
});
