<?php

namespace App\Pricing;

use App\Enums\TokenType;
use InvalidArgumentException;

/**
 * A count of tokens per billable token type.
 */
final readonly class TokenUsage
{
    public function __construct(
        public int $inputText = 0,
        public int $inputImage = 0,
        public int $output = 0,
        public int $reasoning = 0,
    ) {
        foreach ($this->toArray() as $type => $count) {
            if ($count < 0) {
                throw new InvalidArgumentException("Token count for [{$type}] cannot be negative.");
            }
        }
    }

    /**
     * Build from a Pixelforge `usage` object (extra keys such as total_tokens are ignored).
     *
     * @param  array<string, mixed>  $usage
     */
    public static function fromArray(array $usage): self
    {
        $count = function (TokenType $type) use ($usage): int {
            if (! array_key_exists($type->value, $usage)) {
                throw new InvalidArgumentException("Usage is missing [{$type->value}].");
            }

            return (int) $usage[$type->value];
        };

        return new self(
            inputText: $count(TokenType::InputText),
            inputImage: $count(TokenType::InputImage),
            output: $count(TokenType::Output),
            reasoning: $count(TokenType::Reasoning),
        );
    }

    public function get(TokenType $type): int
    {
        return match ($type) {
            TokenType::InputText => $this->inputText,
            TokenType::InputImage => $this->inputImage,
            TokenType::Output => $this->output,
            TokenType::Reasoning => $this->reasoning,
        };
    }

    /** @return array<string, int> keyed by TokenType value */
    public function toArray(): array
    {
        return [
            TokenType::InputText->value => $this->inputText,
            TokenType::InputImage->value => $this->inputImage,
            TokenType::Output->value => $this->output,
            TokenType::Reasoning->value => $this->reasoning,
        ];
    }

    public function total(): int
    {
        return array_sum($this->toArray());
    }
}
