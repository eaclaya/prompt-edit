<?php

namespace Tests\Fakes;

use App\Contracts\CostEstimator;
use App\Contracts\Data\Estimate;
use App\Contracts\Data\EstimateInput;
use App\Support\Money;

/**
 * Returns a fixed estimate. Default: the docs' worked example (high, 1536x1024,
 * 400 characters, 2 reference images): provider max $0.373875, user max $0.46734375.
 */
final class FakeCostEstimator implements CostEstimator
{
    /** @var list<EstimateInput> */
    public array $inputs = [];

    private Estimate $estimate;

    public function __construct()
    {
        $this->estimate = new Estimate(
            providerMin: Money::of('0.286225'),
            providerExpected: Money::of('0.33005'),
            providerMax: Money::of('0.373875'),
            userMin: Money::of('0.35778125'),
            userExpected: Money::of('0.4125625'),
            userMax: Money::of('0.46734375'),
        );
    }

    public static function swap(): self
    {
        return app()->instance(CostEstimator::class, new self);
    }

    public function returns(Estimate $estimate): self
    {
        $this->estimate = $estimate;

        return $this;
    }

    /** Set the user prices; provider prices become user ÷ 1.25 (rounded down). */
    public function returnsUserPrices(string $min, string $expected, string $max): self
    {
        $provider = fn (string $user) => Money::ofNanos(intdiv(Money::of($user)->nanos * 100, 125));

        return $this->returns(new Estimate(
            providerMin: $provider($min),
            providerExpected: $provider($expected),
            providerMax: $provider($max),
            userMin: Money::of($min),
            userExpected: Money::of($expected),
            userMax: Money::of($max),
        ));
    }

    public function estimate(EstimateInput $input): Estimate
    {
        $this->inputs[] = $input;

        return $this->estimate;
    }
}
