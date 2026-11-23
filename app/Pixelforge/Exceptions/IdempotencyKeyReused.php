<?php

namespace App\Pixelforge\Exceptions;

/** 409 idempotency_key_reused: the key was already used with different parameters. */
class IdempotencyKeyReused extends PixelforgeException {}
