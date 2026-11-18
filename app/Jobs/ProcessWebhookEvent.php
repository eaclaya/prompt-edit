<?php

namespace App\Jobs;

use App\Generations\GenerationStateMachine;
use App\Models\WebhookEvent;
use App\Pixelforge\GenerationPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Applies a stored webhook event. Safe to run more than once: the state
 * machine ignores anything that isn't a step forward.
 */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [5, 15, 60, 120];

    public function __construct(public string $eventId)
    {
        $this->onQueue('webhooks');
    }

    public function handle(GenerationStateMachine $machine): void
    {
        $event = WebhookEvent::where('event_id', $this->eventId)->first();

        if ($event === null || $event->processed_at !== null) {
            return;
        }

        $object = $event->payload['data']['object'] ?? null;

        if (str_starts_with($event->type, 'generation.') && is_array($object)) {
            $generation = $machine->apply(GenerationPayload::fromArray($object));

            if ($generation === null) {
                // Another environment's generation, or one we never created.
                Log::info('Webhook for an unknown generation.', ['event' => $event->event_id, 'generation' => $object['id'] ?? null]);
            }
        }

        $event->forceFill(['processed_at' => now()])->save();
    }
}
