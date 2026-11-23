<?php

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Queue;
use Tests\Fakes\SignedWebhook;
use Tests\Fixtures\PixelforgeFixtures;

beforeEach(fn () => Queue::fake());

it('answers a ping with 200 and changes nothing', function () {
    postWebhook(PixelforgeFixtures::event('ping'))->assertOk();

    expect(WebhookEvent::count())->toBe(0);
    Queue::assertNothingPushed();
});

it('stores a new event and queues it for processing', function () {
    $event = PixelforgeFixtures::event('generation.processing');

    postWebhook($event)->assertOk();

    $stored = WebhookEvent::sole();
    expect($stored->event_id)->toBe($event['id'])
        ->and($stored->type)->toBe('generation.processing')
        ->and($stored->generation_provider_id)->toBe($event['data']['object']['id'])
        ->and($stored->payload)->toEqual($event)
        ->and($stored->received_at)->not->toBeNull()
        ->and($stored->processed_at)->toBeNull();

    Queue::assertPushed(ProcessWebhookEvent::class, fn (ProcessWebhookEvent $job) => $job->eventId === $event['id']);
});

it('stores the same evt_ delivered twice once and queues it once', function () {
    $event = PixelforgeFixtures::event('generation.succeeded');

    postWebhook(signedWebhook($event, attempt: 1))->assertOk();
    postWebhook(signedWebhook($event, attempt: 2))->assertOk();

    expect(WebhookEvent::where('event_id', $event['id'])->count())->toBe(1);
    Queue::assertPushedTimes(ProcessWebhookEvent::class, 1);
});

it('rejects a validly signed body that is not an event', function (string $body) {
    $timestamp = now()->getTimestamp();
    $signature = hash_hmac('sha256', "{$timestamp}.{$body}", (string) config('pixelforge.webhook_secret'));
    $webhook = new SignedWebhook($body, ['Content-Type' => 'application/json', 'Pixelforge-Signature' => "t={$timestamp},v1={$signature}"], $timestamp);

    postWebhook($webhook)->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0);
})->with([
    'not json' => ['hello'],
    'no id' => ['{"type":"generation.succeeded"}'],
    'no type' => ['{"id":"evt_1"}'],
]);

it('forgets the event when it cannot be queued, so the retry is processed', function () {
    $event = PixelforgeFixtures::event('generation.processing');
    $this->mock(Dispatcher::class)->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('Redis is down'));

    $this->withoutExceptionHandling();
    expect(fn () => postWebhook($event))->toThrow(RuntimeException::class, 'Redis is down');

    expect(WebhookEvent::count())->toBe(0);
});
