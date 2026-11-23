<?php

namespace App\Pixelforge\Exceptions;

use Illuminate\Http\Client\ConnectionException;

/** Pixelforge could not be reached (DNS, TLS, timeout). Safe to retry with the same Idempotency-Key. */
class ConnectionFailed extends PixelforgeException
{
    public static function from(ConnectionException $e): self
    {
        return new self($e->getMessage(), errorCode: 'connection_failed', previous: $e);
    }

    public function isRetryable(): bool
    {
        return true;
    }
}
