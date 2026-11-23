<?php

namespace App\Pixelforge\Exceptions;

/** An unexpected 5xx response without a documented error code. */
class ServerError extends PixelforgeException
{
    public function isRetryable(): bool
    {
        return true;
    }
}
