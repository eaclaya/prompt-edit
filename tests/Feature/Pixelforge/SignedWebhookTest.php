<?php

use Illuminate\Support\Carbon;
use Tests\Fixtures\PixelforgeFixtures;

/** The docs' reference check: hex HMAC-SHA256 of "{t}.{raw body}" keyed with whsec_, within the tolerance. */
function referenceSignatureIsValid(string $header, string $body, string $secret, int $tolerance = 300): bool
{
    $signature = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
        $signature[$key] = $value;
    }
    $timestamp = (int) ($signature['t'] ?? 0);
    $expected = hash_hmac('sha256', "{$timestamp}.{$body}", $secret);

    return hash_equals($expected, $signature['v1'] ?? '') && abs(time() - $timestamp) <= $tolerance;
}

it('signs a payload so that the docs reference check accepts it', function () {
    $webhook = signedWebhook(PixelforgeFixtures::event('generation.succeeded'));

    expect(referenceSignatureIsValid($webhook->headers['Pixelforge-Signature'], $webhook->body, 'whsec_test_secret'))->toBeTrue()
        ->and(referenceSignatureIsValid($webhook->headers['Pixelforge-Signature'], $webhook->body.' ', 'whsec_test_secret'))->toBeFalse()
        ->and(referenceSignatureIsValid($webhook->headers['Pixelforge-Signature'], $webhook->body, 'whsec_other'))->toBeFalse();
});

it('sends the documented delivery headers', function () {
    $event = PixelforgeFixtures::event('generation.processing');
    $webhook = signedWebhook($event);

    expect($webhook->headers)->toMatchArray([
        'Content-Type' => 'application/json',
        'User-Agent' => 'Pixelforge-Webhooks/1.0',
        'Pixelforge-Event-Id' => $event['id'],
        'Pixelforge-Event-Type' => 'generation.processing',
        'Pixelforge-Delivery-Attempt' => '1',
    ])
        ->and($webhook->headers['Pixelforge-Signature'])->toMatch('/^t=\d+,v1=[0-9a-f]{64}$/')
        ->and(json_decode($webhook->body, true))->toBe($event);
});

it('can sign with an old timestamp or another secret', function () {
    Carbon::setTestNow('2026-11-04 14:03:29');

    $stale = signedWebhook(['id' => 'evt_1'], timestamp: now()->subSeconds(301)->getTimestamp());
    $foreign = signedWebhook(['id' => 'evt_1'], secret: 'whsec_other');

    expect($stale->timestamp)->toBe(now()->getTimestamp() - 301)
        ->and(referenceSignatureIsValid($stale->headers['Pixelforge-Signature'], $stale->body, 'whsec_test_secret'))->toBeFalse()
        ->and(referenceSignatureIsValid($foreign->headers['Pixelforge-Signature'], $foreign->body, 'whsec_test_secret'))->toBeFalse();
});

it('converts the headers for a raw test request', function () {
    $webhook = signedWebhook(['id' => 'evt_1', 'type' => 'ping']);

    expect($webhook->server())->toMatchArray([
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $webhook->headers['Pixelforge-Signature'],
        'HTTP_PIXELFORGE_EVENT_ID' => 'evt_1',
    ]);
});
