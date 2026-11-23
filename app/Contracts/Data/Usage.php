<?php

namespace App\Contracts\Data;

use App\Enums\TokenType;

/** Token counts of one generation (or one usage day). */
final readonly class Usage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
    ) {}

    /** @param  array<string, mixed>  $usage  keys as in the API: input_text_tokens, … */
    public static function fromArray(array $usage): self
    {
        return new self(
            inputTextTokens: self::int($usage, TokenType::InputText),
            inputImageTokens: self::int($usage, TokenType::InputImage),
            outputTokens: self::int($usage, TokenType::Output),
            reasoningTokens: self::int($usage, TokenType::Reasoning),
        );
    }

    public function tokens(TokenType $type): int
    {
        return match ($type) {
            TokenType::InputText => $this->inputTextTokens,
            TokenType::InputImage => $this->inputImageTokens,
            TokenType::Output => $this->outputTokens,
            TokenType::Reasoning => $this->reasoningTokens,
        };
    }

    public function total(): int
    {
        return $this->inputTextTokens + $this->inputImageTokens + $this->outputTokens + $this->reasoningTokens;
    }

    /** @return array{input_text_tokens: int, input_image_tokens: int, output_tokens: int, reasoning_tokens: int, total_tokens: int} */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputTextTokens,
            'input_image_tokens' => $this->inputImageTokens,
            'output_tokens' => $this->outputTokens,
            'reasoning_tokens' => $this->reasoningTokens,
            'total_tokens' => $this->total(),
        ];
    }

    /** @param  array<string, mixed>  $usage */
    private static function int(array $usage, TokenType $type): int
    {
        $value = $usage[$type->value] ?? null;

        if (! is_int($value) || $value < 0) {
            throw new \InvalidArgumentException("Usage is missing a valid [{$type->value}].");
        }

        return $value;
    }
}
