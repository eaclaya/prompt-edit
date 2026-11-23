<?php

namespace App\Contracts\Data;

use App\Enums\TokenType;
use App\Support\Money;
use InvalidArgumentException;

/** Pixelforge rates in US dollars per 1M tokens of each type. */
final readonly class Rates
{
    public const int TOKENS_PER_RATE = 1_000_000;

    public function __construct(
        public Money $inputText,
        public Money $inputImage,
        public Money $output,
        public Money $reasoning,
    ) {}

    /** @param  array<string, mixed>  $rates  decimal strings keyed as in the API, e.g. ['output_tokens' => '40.00', …] */
    public static function fromArray(array $rates): self
    {
        return new self(
            inputText: self::money($rates, TokenType::InputText),
            inputImage: self::money($rates, TokenType::InputImage),
            output: self::money($rates, TokenType::Output),
            reasoning: self::money($rates, TokenType::Reasoning),
        );
    }

    public function rate(TokenType $type): Money
    {
        return match ($type) {
            TokenType::InputText => $this->inputText,
            TokenType::InputImage => $this->inputImage,
            TokenType::Output => $this->output,
            TokenType::Reasoning => $this->reasoning,
        };
    }

    /** @return array{input_text_tokens: string, input_image_tokens: string, output_tokens: string, reasoning_tokens: string} */
    public function toArray(): array
    {
        return [
            'input_text_tokens' => $this->inputText->toDecimal(),
            'input_image_tokens' => $this->inputImage->toDecimal(),
            'output_tokens' => $this->output->toDecimal(),
            'reasoning_tokens' => $this->reasoning->toDecimal(),
        ];
    }

    /** @param  array<string, mixed>  $rates */
    private static function money(array $rates, TokenType $type): Money
    {
        $value = $rates[$type->value] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("Rates are missing a decimal string for [{$type->value}].");
        }

        return Money::of($value);
    }
}
