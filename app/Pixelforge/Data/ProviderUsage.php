<?php

namespace App\Pixelforge\Data;

/** GET /v1/usage: what Pixelforge billed per UTC day of completion (at most 92 days per call). */
final readonly class ProviderUsage
{
    /** @param  list<ProviderUsageDay>  $days */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public array $days,
        public ProviderUsageDay $total,
    ) {}

    /** @param  array<string, mixed>  $usage */
    public static function fromArray(array $usage): self
    {
        /** @var list<array<string, mixed>> $days */
        $days = $usage['data'] ?? [];
        /** @var array<string, mixed> $total */
        $total = $usage['total'] ?? [];

        return new self(
            startDate: (string) $usage['start_date'],
            endDate: (string) $usage['end_date'],
            days: array_map(fn (array $day) => ProviderUsageDay::fromArray($day), $days),
            total: ProviderUsageDay::fromArray($total),
        );
    }

    public function forDate(string $date): ?ProviderUsageDay
    {
        foreach ($this->days as $day) {
            if ($day->date === $date) {
                return $day;
            }
        }

        return null;
    }
}
