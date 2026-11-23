<?php

namespace App\Jobs;

use App\Contracts\GenerationSync;
use App\Contracts\Ledger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\PixelforgeClient;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Redis\LimiterTimeoutException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Redis\Limiters\ConcurrencyLimiter;
use Illuminate\Redis\Limiters\DurationLimiter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
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

    /** Redis keys of the account-wide submission funnel (concurrency slots are KEY1…KEYn). */
    public const string CONCURRENCY_KEY = 'pixelforge:submissions:concurrency:';

    public const string RATE_KEY = 'pixelforge:submissions:per-minute';

    /** A concurrency slot expires on its own if a worker dies mid-request (longer than the HTTP timeouts). */
    private const int FUNNEL_SLOT_TTL = 60;

    /** Pixelforge errors about the user's request or its capacity: its own message is shown to them. */
    private const array USER_FACING_ERRORS = [
        'content_policy_violation', 'daily_limit_exceeded', 'validation_failed',
        'overloaded', 'rate_limit_exceeded', 'concurrency_limit_exceeded',
    ];

    /** Shown instead of Pixelforge's message for errors only the operator can fix (bad key, …). */
    public const string UNAVAILABLE_MESSAGE = 'Image generation is unavailable right now. You were not charged; please try again later.';

    /** Pixelforge accepts only these fields; anything else is a 422. */
    private const array ALLOWED_FIELDS = ['prompt', 'size', 'quality', 'reference_images', 'metadata'];

    public int $tries = self::MAX_ATTEMPTS;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Generation $generation) {}

    /** @return list<Closure(self, Closure): mixed> */
    public function middleware(): array
    {
        return [fn (self $job, Closure $next) => $job->throughFunnel($next)];
    }

    /**
     * Account-wide gate in Redis: at most `limits.max_concurrent` POSTs in flight and
     * `limits.requests_per_minute` per minute. A per-minute slot is spent only once a
     * concurrency slot is held. When either is full the job is released with backoff.
     */
    public function throughFunnel(Closure $next): mixed
    {
        $redis = Redis::connection();
        $concurrency = new ConcurrencyLimiter($redis, self::CONCURRENCY_KEY, (int) config('pixelforge.limits.max_concurrent'), self::FUNNEL_SLOT_TTL);

        try {
            return $concurrency->block(0, function () use ($redis, $next) {
                $rate = new DurationLimiter($redis, self::RATE_KEY, (int) config('pixelforge.limits.requests_per_minute'), 60);

                if (! $rate->acquire()) {
                    return $this->holdBack(min(60, max(1, (int) $rate->decaysAt - time())));
                }

                return $next($this);
            });
        } catch (LimiterTimeoutException) {
            return $this->holdBack();
        }
    }

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
                : $this->failGeneration($generation, $e->errorCode, $this->userMessage($e), $ledger);

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

    /** The funnel is full: try again later, or give up cleanly on the last attempt. */
    private function holdBack(int $atLeast = 0): null
    {
        if ($this->attempts() >= self::MAX_ATTEMPTS) {
            $generation = $this->generation->fresh();

            if ($generation !== null) {
                $this->failGeneration($generation, 'submission_throttled', 'Too many generations are being submitted right now. Please try again. You were not charged.', app(Ledger::class));
            }

            return null;
        }

        $this->release($this->backoff($atLeast));

        return null;
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

    /** Pixelforge's message when it is about the user's request; otherwise a generic one, and the operator is told. */
    private function userMessage(PixelforgeException $e): string
    {
        if (in_array($e->errorCode, self::USER_FACING_ERRORS, true)) {
            return $e->getMessage();
        }

        Log::error('Pixelforge rejected a submission for a reason the user cannot fix.', [
            'generation' => $this->generation->id,
            'code' => $e->errorCode,
            'status' => $e->status,
            'message' => $e->getMessage(),
        ]);

        return self::UNAVAILABLE_MESSAGE;
    }

    /** @return array<string, mixed> */
    private function payload(Generation $generation): array
    {
        return array_intersect_key([
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
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
