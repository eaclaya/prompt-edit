<?php

namespace App\Jobs;

use App\Contracts\GenerationSync;
use App\Contracts\Ledger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Owned by S4. Sends one local generation to Pixelforge (POST /v1/generations).
 *
 * - Idempotency-Key is the generation's stored key (its ULID), so every retry is safe.
 * - metadata.local_id lets a webhook that beats this response find the row.
 * - The 202 goes through GenerationSync, the one forward-only path shared with webhooks and the poller.
 * - Retryable errors release the job after max(Retry-After, capped exponential backoff).
 * - Non-retryable errors, or running out of attempts, mark the generation failed and release the hold.
 */
class SubmitGeneration implements ShouldQueue
{
    use Queueable;

    /** Attempts in total, including releases by the submission funnel. */
    public const int MAX_ATTEMPTS = 10;

    /** Backoff for attempt n is BASE_BACKOFF × 2^(n-1) seconds, at most MAX_BACKOFF. */
    public const int BASE_BACKOFF = 5;

    public const int MAX_BACKOFF = 300;

    /** Pixelforge accepts only these fields; anything else is a 422. */
    private const array ALLOWED_FIELDS = ['prompt', 'size', 'quality', 'reference_images', 'metadata'];

    public int $tries = self::MAX_ATTEMPTS;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Generation $generation) {}

    public function handle(PixelforgeClient $client, GenerationSync $sync, Ledger $ledger): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->status->isFinal()) {
            return;
        }

        try {
            $accepted = $client->createGeneration($this->payload($generation), $generation->idempotency_key);
        } catch (PixelforgeException $e) {
            $e->isRetryable() && $this->attempts() < self::MAX_ATTEMPTS
                ? $this->release($this->backoff($e->retryAfter))
                : $this->failGeneration($generation, $e->errorCode, $e->getMessage(), $ledger);

            return;
        }

        // Record the provider id even if GenerationSync does not; never overwrite what a webhook wrote.
        Generation::whereKey($generation->id)->whereNull('provider_id')->update([
            'provider_id' => $accepted->id,
            'rates' => $accepted->raw['rates'] ?? $accepted->rates->toArray(),
        ]);
        Generation::whereKey($generation->id)->whereNull('submitted_at')->update(['submitted_at' => now()]);

        $sync->apply($accepted);
    }

    /** Seconds to wait before the next attempt: never sooner than Retry-After. */
    public function backoff(?int $retryAfter = null): int
    {
        $exponential = min(self::BASE_BACKOFF * 2 ** max(0, $this->attempts() - 1), self::MAX_BACKOFF);

        return max($retryAfter ?? 0, $exponential);
    }

    /**
     * The job died outside the handled paths (a crash, or the worker's attempt limit).
     * Fail the generation only if Pixelforge never accepted it.
     */
    public function failed(?Throwable $exception): void
    {
        $generation = $this->generation->fresh();

        if ($generation === null || $generation->provider_id !== null) {
            return;
        }

        $this->failGeneration($generation, 'submission_failed', 'The generation could not be submitted. You were not charged.', app(Ledger::class));
    }

    /** @return array<string, mixed> */
    private function payload(Generation $generation): array
    {
        return array_intersect_key([
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => array_values($generation->reference_images),
            'metadata' => ['local_id' => $generation->id],
        ], array_flip(self::ALLOWED_FIELDS));
    }

    /** Only a generation still pending is ours to fail; once a webhook moved it, GenerationSync owns it. */
    private function failGeneration(Generation $generation, string $code, string $message, Ledger $ledger): void
    {
        $updated = Generation::whereKey($generation->id)
            ->where('status', GenerationStatus::Pending)
            ->update([
                'status' => GenerationStatus::Failed,
                'error_code' => $code,
                'error_message' => $message,
                'charged_nanos' => 0,
            ]);

        if ($updated === 1) {
            $ledger->release($generation);
        }
    }
}
