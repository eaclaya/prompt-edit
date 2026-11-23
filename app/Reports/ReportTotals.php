<?php

namespace App\Reports;

use App\Support\Money;

/** Billed generations with what Pixelforge charged us and what we charged users. */
final readonly class ReportTotals
{
    public function __construct(
        public int $generations,
        public Money $providerCost,
        public Money $creditSpend,
    ) {}

    public static function zero(): self
    {
        return new self(0, Money::zero(), Money::zero());
    }

    public function profit(): Money
    {
        return $this->creditSpend->subtract($this->providerCost);
    }

    public function add(self $other): self
    {
        return new self(
            $this->generations + $other->generations,
            $this->providerCost->add($other->providerCost),
            $this->creditSpend->add($other->creditSpend),
        );
    }

    /** @return array{generations: int, provider_cost: string, credit_spend: string, profit: string} */
    public function toArray(): array
    {
        return [
            'generations' => $this->generations,
            'provider_cost' => $this->providerCost->toDecimal(),
            'credit_spend' => $this->creditSpend->toDecimal(),
            'profit' => $this->profit()->toDecimal(),
        ];
    }
}
