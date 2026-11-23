<?php

namespace App\Jobs;

use App\Contracts\GenerationSync;
use App\Models\WebhookEvent;
use App\Pixelforge\Data\ProviderGeneration;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies one stored Pixelforge webhook event (webhook_events.event_id) through GenerationSync,
 * then stamps processed_at. Events for generations we don't know are acknowledged and kept.
 * If this job fails, the generation stays non-final and the reconcile poller picks it up.
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $eventId) {}

    public function handle(GenerationSync $sync): void
    {
        $event = WebhookEvent::query()->where('event_id', $this->eventId)->first();

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $object = $event->payload['data']['object'] ?? null;

        if (is_array($object) && ($object['object'] ?? 'generation') === 'generation' && isset($object['id'], $object['status'])) {
            $sync->apply(ProviderGeneration::fromArray($object));
        }

        $event->forceFill(['processed_at' => now()])->save();
    }
}
