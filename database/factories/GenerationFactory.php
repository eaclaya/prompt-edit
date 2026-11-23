<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Models\Generation;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Generations in every status. Numbers follow the Pixelforge docs' example
 * (high quality, 1536x1024, one reference image).
 *
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'prompt' => fake()->sentence(8),
            'size' => Size::Square,
            'quality' => Quality::Medium,
            'reference_images' => [],
            'status' => GenerationStatus::Pending,
            // Medium 1024x1024 with a ~50-char prompt, user price after 25% markup.
            'est_min_nanos' => Money::of('0.058193750'),
            'est_expected_nanos' => Money::of('0.072268750'),
            'est_max_nanos' => Money::of('0.086343750'),
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => GenerationStatus::Pending]);
    }

    public function queued(): static
    {
        return $this->state(fn () => [
            'status' => GenerationStatus::Queued,
            'provider_id' => self::providerId(),
            'rates' => self::rates(),
            'submitted_at' => now()->subSeconds(20),
        ]);
    }

    public function processing(): static
    {
        return $this->queued()->state(fn () => [
            'status' => GenerationStatus::Processing,
            'started_at' => now()->subSeconds(10),
        ]);
    }

    /** The docs' succeeded example: 12 + 765 + 6450 + 3184 tokens, provider cost $0.305510, charged $0.381887500. */
    public function succeeded(): static
    {
        return $this->processing()->state(fn () => [
            'status' => GenerationStatus::Succeeded,
            'size' => Size::Landscape,
            'quality' => Quality::High,
            'reference_images' => ['https://example.com/style.png'],
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 6450,
                'reasoning_tokens' => 3184,
                'total_tokens' => 10411,
            ],
            'est_min_nanos' => Money::of('0.347750000'),
            'est_expected_nanos' => Money::of('0.402450000'),
            'est_max_nanos' => Money::of('0.457150000'),
            'provider_cost_nanos' => Money::of('0.305510000'),
            'charged_nanos' => Money::of('0.381887500'),
            'image_url' => 'https://pixelforge.promptedit.dev/images/'.Str::random(8).'.png?expires=1762351409&signature=test',
            'image_url_expires_at' => now()->addDay(),
            'completed_at' => now(),
        ]);
    }

    public function failed(string $code = 'generation_failed', string $message = "The model couldn't produce an image for this prompt."): static
    {
        return $this->processing()->state(fn () => [
            'status' => GenerationStatus::Failed,
            'error_code' => $code,
            'error_message' => $message,
            'charged_nanos' => Money::zero(),
            'completed_at' => now(),
        ]);
    }

    public function imageExpired(): static
    {
        return $this->state(fn () => [
            'image_url_expires_at' => now()->subMinute(),
        ]);
    }

    private static function providerId(): string
    {
        return 'gen_'.Str::upper((string) Str::ulid());
    }

    /** @return array<string, string> */
    private static function rates(): array
    {
        return [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ];
    }
}
