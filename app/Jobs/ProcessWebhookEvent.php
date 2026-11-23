<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Applies one stored Pixelforge webhook event (webhook_events.event_id) through GenerationSync. */
class ProcessWebhookEvent implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $eventId) {}

    public function handle(): void {}
}
