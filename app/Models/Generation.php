<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Pricing\TokenUsage;
use Carbon\CarbonImmutable;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One image generation. Its ULID is also the Idempotency-Key sent to
 * Pixelforge and the `local_id` in the generation's metadata.
 *
 * Unguarded: rows are only ever written by our own code from validated input.
 *
 * @property string $id
 * @property int|null $user_id
 * @property string|null $provider_id
 * @property GenerationStatus $status
 * @property string $prompt
 * @property Size $size
 * @property Quality $quality
 * @property list<string> $reference_images
 * @property int $est_cost_min_nano
 * @property int $est_cost_max_nano
 * @property int $est_price_min_nano
 * @property int $est_price_max_nano
 * @property array<string, string>|null $rates
 * @property int|null $input_text_tokens
 * @property int|null $input_image_tokens
 * @property int|null $output_tokens
 * @property int|null $reasoning_tokens
 * @property int|null $provider_cost_nano
 * @property int|null $price_nano
 * @property string|null $image_url
 * @property CarbonImmutable|null $image_url_expires_at
 * @property string|null $image_path
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int $submit_attempts
 * @property CarbonImmutable|null $last_synced_at
 * @property CarbonImmutable|null $provider_created_at
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    protected $attributes = [
        'status' => 'pending',
        'reference_images' => '[]',
        'submit_attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => GenerationStatus::class,
            'size' => Size::class,
            'quality' => Quality::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'est_cost_min_nano' => 'integer',
            'est_cost_max_nano' => 'integer',
            'est_price_min_nano' => 'integer',
            'est_price_max_nano' => 'integer',
            'input_text_tokens' => 'integer',
            'input_image_tokens' => 'integer',
            'output_tokens' => 'integer',
            'reasoning_tokens' => 'integer',
            'provider_cost_nano' => 'integer',
            'price_nano' => 'integer',
            'submit_attempts' => 'integer',
            'image_url_expires_at' => 'datetime',
            'last_synced_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    /** Actual token usage, once the generation has succeeded. */
    public function usage(): ?TokenUsage
    {
        if ($this->input_text_tokens === null) {
            return null;
        }

        return new TokenUsage(
            inputText: $this->input_text_tokens,
            inputImage: (int) $this->input_image_tokens,
            output: (int) $this->output_tokens,
            reasoning: (int) $this->reasoning_tokens,
        );
    }

    public function isInFlight(): bool
    {
        return ! $this->status->isFinal();
    }

    /** @param  Builder<self>  $query */
    public function scopeInFlight(Builder $query): void
    {
        $query->whereIn('status', GenerationStatus::inFlight());
    }

    /** @param  Builder<self>  $query */
    public function scopeAtProvider(Builder $query): void
    {
        $query->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing]);
    }
}
