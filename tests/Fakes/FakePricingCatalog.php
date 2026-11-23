<?php

namespace Tests\Fakes;

use App\Contracts\Data\Rates;
use App\Contracts\Data\TokenModel;
use App\Contracts\PricingCatalog;

/** The documented pricing from config('pixelforge.pricing'), with optional rate overrides. No HTTP. */
final class FakePricingCatalog implements PricingCatalog
{
    /** @var array<string, string> */
    private array $rates;

    public function __construct()
    {
        $this->rates = config('pixelforge.pricing.rates');
    }

    public static function swap(): self
    {
        return app()->instance(PricingCatalog::class, new self);
    }

    /** @param  array<string, string>  $rates  e.g. ['output_tokens' => '50.00'] */
    public function withRates(array $rates): self
    {
        $this->rates = [...$this->rates, ...$rates];

        return $this;
    }

    public function current(): Rates
    {
        return Rates::fromArray($this->rates);
    }

    public function tokenModel(): TokenModel
    {
        return TokenModel::fromArray(config('pixelforge.pricing.tokens'));
    }
}
