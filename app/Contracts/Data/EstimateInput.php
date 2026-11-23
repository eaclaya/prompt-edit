<?php

namespace App\Contracts\Data;

use App\Enums\Quality;
use App\Enums\Size;

/** What a cost estimate needs from a generation request. */
final readonly class EstimateInput
{
    public function __construct(
        public string $prompt,
        public Size $size,
        public Quality $quality,
        public int $referenceImageCount = 0,
    ) {}

    /** Unicode characters, not bytes (text tokens ≈ ceil(chars / 4)). */
    public function promptCharacters(): int
    {
        return mb_strlen($this->prompt, 'UTF-8');
    }
}
