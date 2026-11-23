<?php

namespace App\Pixelforge\Data;

use App\Contracts\Data\Usage;
use App\Support\Money;

/** One UTC day of GET /v1/usage (or its total), by day of completion. */
final readonly class ProviderUsageDay
{
    public function __construct(
        public ?string $date,
        public int $generations,
        public Usage $usage,
        public Money $cost,
    ) {}

    /** @param  array<string, mixed>  $day */
    public static function fromArray(array $day): self
    {
        return new self(
            date: isset($day['date']) ? (string) $day['date'] : null,
            generations: (int) ($day['generations'] ?? 0),
            usage: Usage::fromArray($day),
            cost: Money::of((string) ($day['cost_usd'] ?? '0')),
        );
    }
}
