<?php

namespace App\Http\Resources;

use App\Contracts\Data\Rates;
use App\Contracts\Data\Usage;
use App\Enums\GenerationStatus;
use App\Enums\TokenType;
use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GenerationSummary, or GenerationDetail after ->detailed() (resources/js/types/generations.ts).
 * Money goes out as nine-decimal strings.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    private bool $detailed = false;

    /** Adds usage, rates, the cost breakdown, image_url_expires_at and started_at. */
    public function detailed(): static
    {
        $this->detailed = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->detailed
            ? [...$this->summary(), ...$this->detail()]
            : $this->summary();
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $expired = $this->imageExpired();

        return [
            'id' => $this->id,
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
            'reference_images' => $this->reference_images,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'is_final' => $this->status->isFinal(),
            'estimate' => [
                'min' => $this->est_min_nanos->toDecimal(),
                'expected' => $this->est_expected_nanos->toDecimal(),
                'max' => $this->est_max_nanos->toDecimal(),
            ],
            'charged' => $this->status->isFinal() ? $this->charged_nanos?->toDecimal() : null,
            'image_url' => $expired ? null : $this->image_url,
            'image_expired' => $expired,
            'error' => $this->error_code === null ? null : [
                'code' => $this->error_code,
                'message' => (string) $this->error_message,
            ],
            'created_at' => $this->created_at?->toJSON(),
            'completed_at' => $this->completed_at?->toJSON(),
        ];
    }

    /** @return array<string, mixed> */
    private function detail(): array
    {
        $rates = $this->rates === null ? null : Rates::fromArray($this->rates);

        return [
            'usage' => $this->usage,
            'rates' => $rates?->toArray(),
            'breakdown' => $this->breakdown($rates),
            'image_url_expires_at' => $this->image_url_expires_at?->toJSON(),
            'started_at' => $this->started_at?->toJSON(),
        ];
    }

    /**
     * The cost math: tokens × rate / 1M per token type, the stored provider cost, the markup and the charge.
     * Only once a generation has succeeded with usage, rates and a settled charge.
     *
     * @return array<string, mixed>|null
     */
    private function breakdown(?Rates $rates): ?array
    {
        if ($this->status !== GenerationStatus::Succeeded
            || $rates === null || $this->usage === null
            || $this->provider_cost_nanos === null || $this->charged_nanos === null) {
            return null;
        }

        $usage = Usage::fromArray($this->usage);

        return [
            'lines' => array_map(fn (TokenType $type) => [
                'token_type' => $type->value,
                'tokens' => $usage->tokens($type),
                'rate' => $rates->rate($type)->toDecimal(),
                'cost' => $rates->rate($type)->multiplyRatio($usage->tokens($type), Rates::TOKENS_PER_RATE)->toDecimal(),
            ], TokenType::cases()),
            'provider_cost' => $this->provider_cost_nanos->toDecimal(),
            'markup_bps' => (int) config('pixelforge.markup_bps'),
            'charged' => $this->charged_nanos->toDecimal(),
        ];
    }

    private function imageExpired(): bool
    {
        return $this->image_url_expires_at !== null && ! $this->image_url_expires_at->isFuture();
    }
}
