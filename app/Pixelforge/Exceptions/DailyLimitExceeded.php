<?php

namespace App\Pixelforge\Exceptions;

/** 429 daily_limit_exceeded: the account's daily quota (UTC) is used up. Not retryable today. */
class DailyLimitExceeded extends PixelforgeException {}
