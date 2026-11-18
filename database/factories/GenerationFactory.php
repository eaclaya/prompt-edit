<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Defaults match a 100-character, medium, square prompt priced at the
 * published rates.
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'prompt' => str_repeat('a', 100),
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'reference_images' => [],
            'est_cost_min_nano' => 46_605_000,
            'est_cost_max_nano' => 69_145_000,
            'est_price_min_nano' => 58_256_250,
            'est_price_max_nano' => 86_431_250,
        ];
    }

    public function atProvider(GenerationStatus $status = GenerationStatus::Queued): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'provider_id' => 'gen_'.Str::lower(Str::random(24)),
            'provider_created_at' => now(),
        ]);
    }
}
