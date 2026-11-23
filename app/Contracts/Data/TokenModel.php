<?php

namespace App\Contracts\Data;

use App\Enums\Quality;
use App\Enums\Size;
use InvalidArgumentException;

/** How Pixelforge counts tokens (the `tokens` block of GET /v1/pricing). */
final readonly class TokenModel
{
    /**
     * @param  int  $textTokenVarianceBps  ±variance of the real text token count, in basis points (0.15 → 1500)
     * @param  array<string, array<string, int>>  $output  [quality][size] => exact output tokens
     * @param  array<string, array{min: int, max: int}>  $reasoning  [quality] => reasoning token range
     */
    public function __construct(
        public int $charactersPerTextToken,
        public int $textTokenVarianceBps,
        public int $perReferenceImage,
        public array $output,
        public array $reasoning,
    ) {}

    /** @param  array<string, mixed>  $tokens */
    public static function fromArray(array $tokens): self
    {
        $variance = $tokens['text_token_variance'] ?? null;

        if (! is_int($variance) && ! is_float($variance) && ! is_string($variance)) {
            throw new InvalidArgumentException('The token model is missing text_token_variance.');
        }

        /** @var array<string, array<string, int>> $output */
        $output = $tokens['output'] ?? [];
        /** @var array<string, array{min: int, max: int}> $reasoning */
        $reasoning = $tokens['reasoning'] ?? [];

        return new self(
            charactersPerTextToken: (int) ($tokens['characters_per_text_token'] ?? 0),
            // The API sends a JSON number (0.15); keep integers from here on.
            textTokenVarianceBps: (int) round(((float) $variance) * 10_000),
            perReferenceImage: (int) ($tokens['per_reference_image'] ?? 0),
            output: $output,
            reasoning: $reasoning,
        );
    }

    public function outputTokens(Quality $quality, Size $size): int
    {
        return $this->output[$quality->value][$size->value]
            ?? throw new InvalidArgumentException("No output tokens for [{$quality->value}, {$size->value}].");
    }

    public function minReasoningTokens(Quality $quality): int
    {
        return $this->reasoningRange($quality)['min'];
    }

    public function maxReasoningTokens(Quality $quality): int
    {
        return $this->reasoningRange($quality)['max'];
    }

    /** @return array{min: int, max: int} */
    private function reasoningRange(Quality $quality): array
    {
        return $this->reasoning[$quality->value]
            ?? throw new InvalidArgumentException("No reasoning range for [{$quality->value}].");
    }
}
