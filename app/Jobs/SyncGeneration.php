<?php

namespace App\Jobs;

use App\Generations\GenerationStateMachine;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Log;

/**
 * Polls Pixelforge for a generation's current state. The fallback for
 * webhooks that are late or never arrive.
 */
class SyncGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $uniqueFor = 120;

    public function __construct(public string $generationId) {}

    public function uniqueId(): string
    {
        return $this->generationId;
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('pixelforge-api')];
    }

    public function handle(PixelforgeClient $client, GenerationStateMachine $machine): void
    {
        $generation = Generation::find($this->generationId);

        if ($generation === null || $generation->provider_id === null || ! $generation->isInFlight()) {
            return;
        }

        try {
            $machine->apply($client->getGeneration($generation->provider_id));
        } catch (PixelforgeException $e) {
            if ($e->isRetryable()) {
                $this->release($e->retryAfter ?? 10);

                return;
            }

            Log::warning('Could not sync generation.', [
                'generation' => $generation->id,
                'code' => $e->errorCode,
            ]);
            $generation->forceFill(['last_synced_at' => now()])->save();
        }
    }
}
