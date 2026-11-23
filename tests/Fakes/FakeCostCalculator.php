<?php

namespace Tests\Fakes;

use App\Contracts\CostCalculator;
use App\Contracts\Data\Rates;
use App\Contracts\Data\Usage;
use App\Enums\TokenType;
use App\Support\Money;

/** Σ tokens × rate / 1M (each line rounded up), or a fixed cost. */
final class FakeCostCalculator implements CostCalculator
{
    /** @var list<array{usage: Usage, rates: Rates}> */
    public array $calls = [];

    private ?Money $fixed = null;

    public static function swap(): self
    {
        return app()->instance(CostCalculator::class, new self);
    }

    public function returns(Money $cost): self
    {
        $this->fixed = $cost;

        return $this;
    }

    public function providerCost(Usage $usage, Rates $rates): Money
    {
        $this->calls[] = ['usage' => $usage, 'rates' => $rates];

        return $this->fixed ?? Money::sum(array_map(
            fn (TokenType $type) => $rates->rate($type)->multiplyRatio($usage->tokens($type), Rates::TOKENS_PER_RATE),
            TokenType::cases(),
        ));
    }
}
