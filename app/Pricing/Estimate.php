<?php

namespace App\Pricing;

use App\Enums\TokenType;
use App\Support\Money;

/**
 * The cost range of a generation before it runs. The maximum is what we
 * hold from the user's balance and promise never to exceed.
 */
final readonly class Estimate
{
    /**
     * @param  array<string, string>  $rates  the rates the estimate was computed with
     */
    public function __construct(
        public TokenUsage $minTokens,
        public TokenUsage $maxTokens,
        public int $costMinNano,
        public int $costMaxNano,
        public int $priceMinNano,
        public int $priceMaxNano,
        public array $rates,
        public int $markupBasisPoints,
    ) {}

    /**
     * Shape sent to the UI. Amounts are strings so JavaScript never turns
     * them into floats; `*_display` values are for showing only.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $lines = [];

        foreach (TokenType::cases() as $type) {
            $lines[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'known_upfront' => $type->isKnownUpfront(),
                'tokens_min' => $this->minTokens->get($type),
                'tokens_max' => $this->maxTokens->get($type),
                'rate_per_million' => $this->rates[$type->value],
            ];
        }

        return [
            'lines' => $lines,
            'markup_percent' => $this->markupBasisPoints / 100,
            'cost_min_nano' => (string) $this->costMinNano,
            'cost_max_nano' => (string) $this->costMaxNano,
            'price_min_nano' => (string) $this->priceMinNano,
            'price_max_nano' => (string) $this->priceMaxNano,
            'cost_min_display' => Money::format($this->costMinNano),
            'cost_max_display' => Money::format($this->costMaxNano),
            'price_min_display' => Money::format($this->priceMinNano),
            'price_max_display' => Money::format($this->priceMaxNano),
        ];
    }
}
