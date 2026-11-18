<?php

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Generations\GenerationStateMachine;
use App\Models\Generation;
use App\Pixelforge\PixelforgeClient;
use App\Pixelforge\PixelforgeException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Sends a pending generation to Pixelforge.
 *
 * Every attempt uses the generation's ULID as the Idempotency-Key, so a retry
 * after a timeout returns the original generation instead of creating a
 * second one. Capacity errors (rate, concurrency, overload) are retried after
 * Retry-After. Definitive rejections fail the generation locally, which is
 * free, because nothing was created at Pixelforge.
 */
class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /** Give up on capacity errors after this long and tell the user. */
    public const GIVE_UP_AFTER_MINUTES = 15;

    /** Unlimited: the job decides when to stop (see GIVE_UP_AFTER_MINUTES). */
    public int $tries = 0;

    /** Unexpected exceptions (bugs) are not retried forever. */
    public int $maxExceptions = 3;

    /** If a worker dies mid-job, let the reconciler dispatch it again. */
    public int $uniqueFor = 300;

    public function __construct(public string $generationId)
    {
        $this->onQueue('submissions');
    }

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

        if ($generation === null || $generation->status !== GenerationStatus::Pending) {
            return;
        }

        // Pixelforge allows a few generations in flight per account. Wait for
        // a slot here instead of spending a request on a certain 429.
        if (Generation::atProvider()->count() >= config('pixelforge.max_concurrent')) {
            $this->release(5);

            return;
        }

        $generation->increment('submit_attempts');

        try {
            $payload = $client->createGeneration($generation);
        } catch (PixelforgeException $e) {
            $this->handleError($generation, $e, $machine);

            return;
        }

        $machine->apply($payload);
    }

    private function handleError(Generation $generation, PixelforgeException $e, GenerationStateMachine $machine): void
    {
        if (! $e->isRetryable()) {
            if (! in_array($e->errorCode, [PixelforgeException::CONTENT_POLICY_VIOLATION, PixelforgeException::DAILY_LIMIT_EXCEEDED], true)) {
                report($e); // a bug or misconfiguration on our side
            }

            $machine->failLocally($generation, $e->errorCode, $e->getMessage());

            return;
        }

        // A capacity error means Pixelforge created nothing, so after long
        // enough we can safely give up. A timeout or unknown server error is
        // different: the generation may exist, so we keep retrying with the
        // same Idempotency-Key until Pixelforge answers.
        $nothingCreated = $e->errorCode !== PixelforgeException::CONNECTION_FAILED
            && $e->errorCode !== PixelforgeException::UNEXPECTED_RESPONSE;

        if ($nothingCreated && $generation->created_at->addMinutes(self::GIVE_UP_AFTER_MINUTES)->isPast()) {
            $machine->failLocally(
                $generation,
                'retries_exhausted',
                'Pixelforge was too busy to accept this generation.',
            );

            return;
        }

        $this->release($this->retryDelay($e, $generation->submit_attempts));
    }

    private function retryDelay(PixelforgeException $e, int $attempt): int
    {
        $backoff = min(2 ** min($attempt, 6), 60);

        return max($e->retryAfter ?? 0, $backoff) + random_int(0, 2);
    }
}
