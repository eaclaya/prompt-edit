<?php

namespace App\Pixelforge\Exceptions;

/** 429 concurrency_limit_exceeded: 3 generations already queued or processing. Retry after $retryAfter (5) seconds. */
class ConcurrencyLimited extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
