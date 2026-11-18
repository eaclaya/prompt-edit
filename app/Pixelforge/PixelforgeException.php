<?php

namespace App\Pixelforge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A failed Pixelforge API call. Callers branch on $errorCode, which is
 * Pixelforge's `error.code`, or one of our own codes for transport failures.
 */
final class PixelforgeException extends RuntimeException
{
    public const INVALID_API_KEY = 'invalid_api_key';

    public const VALIDATION_FAILED = 'validation_failed';

    public const CONTENT_POLICY_VIOLATION = 'content_policy_violation';

    public const NOT_FOUND = 'not_found';

    public const IDEMPOTENCY_KEY_REUSED = 'idempotency_key_reused';

    public const RATE_LIMIT_EXCEEDED = 'rate_limit_exceeded';

    public const CONCURRENCY_LIMIT_EXCEEDED = 'concurrency_limit_exceeded';

    public const DAILY_LIMIT_EXCEEDED = 'daily_limit_exceeded';

    public const OVERLOADED = 'overloaded';

    /** Ours: no response at all (timeout, DNS, refused connection). */
    public const CONNECTION_FAILED = 'connection_failed';

    /** Ours: an error response without a recognisable error body. */
    public const UNEXPECTED_RESPONSE = 'unexpected_response';

    /** Retry-After values the docs give when the header is missing. */
    private const DEFAULT_RETRY_AFTER = [
        self::CONCURRENCY_LIMIT_EXCEEDED => 5,
        self::OVERLOADED => 2,
    ];

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $status = null,
        public readonly ?int $retryAfter = null,
        public readonly ?string $param = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function fromResponse(Response $response): self
    {
        $error = $response->json('error');
        $code = is_array($error) && isset($error['code']) ? (string) $error['code'] : self::UNEXPECTED_RESPONSE;
        $message = is_array($error) && isset($error['message'])
            ? (string) $error['message']
            : "Pixelforge responded with HTTP {$response->status()}.";

        $header = $response->header('Retry-After');
        $retryAfter = is_numeric($header) ? max(0, (int) $header) : (self::DEFAULT_RETRY_AFTER[$code] ?? null);

        return new self(
            message: $message,
            errorCode: $code,
            status: $response->status(),
            retryAfter: $retryAfter,
            param: is_array($error) && isset($error['param']) ? (string) $error['param'] : null,
        );
    }

    public static function connectionFailed(ConnectionException $e): self
    {
        return new self('Could not reach Pixelforge: '.$e->getMessage(), self::CONNECTION_FAILED, previous: $e);
    }

    /**
     * Whether trying the same request again later may succeed. Transport
     * failures are retryable because a request with the same Idempotency-Key
     * can't create a second generation.
     */
    public function isRetryable(): bool
    {
        return in_array($this->errorCode, [
            self::RATE_LIMIT_EXCEEDED,
            self::CONCURRENCY_LIMIT_EXCEEDED,
            self::OVERLOADED,
            self::CONNECTION_FAILED,
        ], true) || ($this->errorCode === self::UNEXPECTED_RESPONSE && ($this->status ?? 500) >= 500);
    }
}
