<?php

namespace App\Pixelforge\Data;

use App\Contracts\Data\Rates;
use App\Contracts\Data\TokenModel;

/** GET /v1/pricing. */
final readonly class ProviderPricing
{
    /** @param  array<string, mixed>  $raw  the response exactly as received (safe to cache) */
    public function __construct(
        public Rates $rates,
        public TokenModel $tokens,
        public array $raw,
    ) {}

    /** @param  array<string, mixed>  $pricing  the response body, or config('pixelforge.pricing') */
    public static function fromArray(array $pricing): self
    {
        /** @var array<string, mixed> $rates */
        $rates = $pricing['rates'] ?? [];
        /** @var array<string, mixed> $tokens */
        $tokens = $pricing['tokens'] ?? [];

        return new self(Rates::fromArray($rates), TokenModel::fromArray($tokens), $pricing);
    }
}
