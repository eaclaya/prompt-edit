<?php

namespace App\Pixelforge\Exceptions;

use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * A Pixelforge API error. Branch on the subclass (or $errorCode, the API's `code`).
 * Retryable errors carry the Retry-After seconds when Pixelforge sent them.
 */
class PixelforgeException extends RuntimeException
{
    /** @var array<string, class-string<PixelforgeException>> */
    private const array BY_CODE = [
        'invalid_api_key' => InvalidApiKey::class,
        'validation_failed' => ValidationFailed::class,
        'content_policy_violation' => ContentPolicyViolation::class,
        'not_found' => NotFound::class,
        'idempotency_key_reused' => IdempotencyKeyReused::class,
        'rate_limit_exceeded' => RateLimited::class,
        'concurrency_limit_exceeded' => ConcurrencyLimited::class,
        'daily_limit_exceeded' => DailyLimitExceeded::class,
        'overloaded' => Overloaded::class,
        'date_range_too_long' => DateRangeTooLong::class,
    ];

    /** @param  array<string, list<string>>  $errors */
    final public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?string $errorType = null,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $param = null,
        public readonly array $errors = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /** Build the matching exception from a failed response. */
    public static function fromResponse(Response $response, ?Throwable $previous = null): self
    {
        /** @var array{type?: string, code?: string, message?: string, param?: string, errors?: array<string, list<string>>} $error */
        $error = is_array($response->json('error')) ? $response->json('error') : [];
        $code = $error['code'] ?? ($response->serverError() ? 'server_error' : 'unknown_error');
        $class = self::BY_CODE[$code] ?? ($response->serverError() ? ServerError::class : self::class);
        $retryAfter = $response->header('Retry-After');

        return new $class(
            message: $error['message'] ?? "Pixelforge responded with HTTP {$response->status()}.",
            errorCode: $code,
            errorType: $error['type'] ?? null,
            status: $response->status(),
            retryAfter: is_numeric($retryAfter) ? (int) $retryAfter : null,
            param: $error['param'] ?? null,
            errors: $error['errors'] ?? [],
            previous: $previous,
        );
    }

    /** Whether the same request may succeed later (after $retryAfter seconds, if set). */
    public function isRetryable(): bool
    {
        return false;
    }
}
