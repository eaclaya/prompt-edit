<?php

namespace App\Pixelforge\Exceptions;

/** 503 overloaded: nothing was created. Retry after $retryAfter (2) seconds, with the same Idempotency-Key. */
class Overloaded extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
