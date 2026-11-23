<?php

namespace App\Reports;

/** One user's billed generations over a range. A null user is every deleted account together. */
final readonly class UserTotals
{
    public function __construct(
        public ?int $userId,
        public string $name,
        public string $email,
        public ReportTotals $totals,
    ) {}

    /** @return array{user_id: int|null, name: string, email: string, generations: int, provider_cost: string, credit_spend: string, profit: string} */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'name' => $this->name,
            'email' => $this->email,
            ...$this->totals->toArray(),
        ];
    }
}
