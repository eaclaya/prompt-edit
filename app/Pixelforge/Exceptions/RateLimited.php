<?php

namespace App\Pixelforge\Exceptions;

/** 429 rate_limit_exceeded: too many requests this minute. Retry after $retryAfter seconds. */
class RateLimited extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
