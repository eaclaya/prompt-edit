<?php

namespace App\Pixelforge\Exceptions;

/** 422 validation_failed: see $param (the first invalid parameter) and $errors (every problem). */
class ValidationFailed extends PixelforgeException {}
