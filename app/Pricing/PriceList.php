<?php

namespace App\Pricing;

use App\Enums\Quality;
use App\Enums\Size;
use App\Enums\TokenType;
use InvalidArgumentException;

/**
 * Pixelforge's published pricing, in the exact shape of GET /v1/pricing.
 * Used for estimates only; actual costs use the rates on each generation.
 */
final readonly class PriceList
{
    /**
     * @param  array<string, string>  $rates  USD per 1M tokens, keyed by TokenType value
     * @param  array<string, mixed>  $tokens  token tables from the pricing response
     */
    public function __construct(
        public array $rates,
        public array $tokens,
    ) {
        foreach (TokenType::cases() as $type) {
            if (! isset($rates[$type->value])) {
                throw new InvalidArgumentException("Price list is missing the [{$type->value}] rate.");
            }
        }

        foreach (Quality::cases() as $quality) {
            if (! isset($tokens['reasoning'][$quality->value]['min'], $tokens['reasoning'][$quality->value]['max'])) {
                throw new InvalidArgumentException("Price list is missing reasoning tokens for [{$quality->value}].");
            }

            foreach (Size::cases() as $size) {
                if (! isset($tokens['output'][$quality->value][$size->value])) {
                    throw new InvalidArgumentException("Price list is missing output tokens for [{$quality->value}, {$size->value}].");
                }
            }
        }

        foreach (['characters_per_text_token', 'text_token_variance', 'per_reference_image'] as $key) {
            if (! isset($tokens[$key])) {
                throw new InvalidArgumentException("Price list is missing [{$key}].");
            }
        }
    }

    /** @param  array<string, mixed>  $data  a GET /v1/pricing response body */
    public static function fromArray(array $data): self
    {
        return new self(
            rates: array_map('strval', $data['rates'] ?? []),
            tokens: $data['tokens'] ?? [],
        );
    }

    public function rate(TokenType $type): string
    {
        return $this->rates[$type->value];
    }

    public function outputTokens(Quality $quality, Size $size): int
    {
        return (int) $this->tokens['output'][$quality->value][$size->value];
    }

    /** @return array{0: int, 1: int} [min, max] */
    public function reasoningTokenRange(Quality $quality): array
    {
        $range = $this->tokens['reasoning'][$quality->value];

        return [(int) $range['min'], (int) $range['max']];
    }

    public function tokensPerReferenceImage(): int
    {
        return (int) $this->tokens['per_reference_image'];
    }

    public function charactersPerTextToken(): int
    {
        return (int) $this->tokens['characters_per_text_token'];
    }

    /**
     * The ±variance of actual text tokens around the estimate, as a decimal
     * string ("0.15") so callers can do exact arithmetic with it.
     */
    public function textTokenVariance(): string
    {
        return (string) $this->tokens['text_token_variance'];
    }
}
