<?php

namespace App\Console\Commands;

use App\Contracts\GenerationSync;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fallback for missed webhooks: polls Pixelforge for non-final generations that have gone quiet
 * and applies the result through GenerationSync (idempotent, forward-only). Runs every minute.
 */
class ReconcileGenerations extends Command
{
    protected $signature = 'generations:reconcile
        {--quiet-for=60 : Seconds without an update before a generation is polled}
        {--limit=20 : Most generations polled per run (stays well inside 60 req/min)}';

    protected $description = 'Poll Pixelforge for non-final generations that have not been updated recently';

    public function handle(PixelforgeClient $client, GenerationSync $sync): int
    {
        $generations = Generation::query()
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->whereNotNull('provider_id')
            ->where('updated_at', '<', now()->subSeconds((int) $this->option('quiet-for')))
            ->orderBy('updated_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get(['id', 'provider_id']);

        foreach ($generations as $generation) {
            try {
                $sync->apply($client->getGeneration((string) $generation->provider_id));
            } catch (PixelforgeException $e) {
                Log::warning('Reconcile could not fetch a generation.', ['generation' => $generation->id, 'code' => $e->errorCode]);

                if ($e->isRetryable()) {
                    break; // rate-limited / overloaded / down: try again next minute
                }
            } catch (Throwable $e) {
                report($e);
            }
        }

        $this->info("Polled {$generations->count()} generation(s).");

        return self::SUCCESS;
    }
}
