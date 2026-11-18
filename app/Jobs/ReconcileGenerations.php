<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs every minute and recovers anything that got stuck:
 *  - generations at Pixelforge we haven't heard about for a while are polled;
 *  - pending generations whose submit job was lost are submitted again
 *    (safe: same Idempotency-Key);
 *  - stored webhook events that were never processed are processed again.
 */
class ReconcileGenerations implements ShouldQueue
{
    use Queueable;

    /** Seconds without news before we poll a generation ourselves. */
    public const STALE_AFTER = 60;

    /** At most this many polls per run, well inside the request budget. */
    public const MAX_SYNCS_PER_RUN = 20;

    public const STUCK_AFTER = 120;

    public function handle(): void
    {
        $staleBefore = now()->subSeconds(self::STALE_AFTER);

        Generation::atProvider()
            ->where('updated_at', '<', $staleBefore)
            ->where(fn ($q) => $q->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $staleBefore))
            ->orderBy('last_synced_at')
            ->limit(self::MAX_SYNCS_PER_RUN)
            ->pluck('id')
            ->each(fn (string $id) => SyncGeneration::dispatch($id));

        Generation::where('status', GenerationStatus::Pending)
            ->where('updated_at', '<', now()->subSeconds(self::STUCK_AFTER))
            ->pluck('id')
            ->each(fn (string $id) => SubmitGeneration::dispatch($id));

        WebhookEvent::whereNull('processed_at')
            ->where('received_at', '<', now()->subSeconds(self::STUCK_AFTER))
            ->pluck('event_id')
            ->each(fn (string $eventId) => ProcessWebhookEvent::dispatch($eventId));
    }
}
