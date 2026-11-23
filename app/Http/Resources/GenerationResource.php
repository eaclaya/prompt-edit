<?php

namespace App\Http\Resources;

use App\Models\Generation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * GenerationSummary (resources/js/types/generations.ts). Money goes out as nine-decimal strings.
 *
 * @mixin Generation
 */
class GenerationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
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

    private function imageExpired(): bool
    {
        return $this->image_url_expires_at !== null && ! $this->image_url_expires_at->isFuture();
    }
}
