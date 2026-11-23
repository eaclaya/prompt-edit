<?php

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\Quality;
use App\Enums\Size;
use App\Support\Money;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One image generation requested by a user. Money columns are nanodollars, cast to Money.
 *
 * @property string $id ULID; also the default idempotency key and metadata.local_id
 * @property int|null $user_id
 * @property string|null $provider_id gen_…
 * @property string $idempotency_key
 * @property string $prompt
 * @property Size $size
 * @property Quality $quality
 * @property list<string> $reference_images
 * @property GenerationStatus $status
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array<string, string>|null $rates USD per 1M tokens, keyed by TokenType value
 * @property Money $est_min_nanos user price
 * @property Money $est_expected_nanos user price
 * @property Money $est_max_nanos user price (the amount held)
 * @property array<string, int>|null $usage token counts, incl. total_tokens
 * @property Money|null $provider_cost_nanos
 * @property Money|null $charged_nanos
 * @property string|null $image_url
 * @property Carbon|null $image_url_expires_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Collection<int, CreditTransaction> $creditTransactions
 */
class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory, HasUlids;

    protected $guarded = [];

    protected $attributes = [
        'status' => 'pending',
        'reference_images' => '[]',
    ];

    protected static function booted(): void
    {
        static::creating(function (Generation $generation) {
            $generation->idempotency_key ??= $generation->id;
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => Size::class,
            'quality' => Quality::class,
            'status' => GenerationStatus::class,
            'reference_images' => 'array',
            'rates' => 'array',
            'usage' => 'array',
            'est_min_nanos' => Money::class,
            'est_expected_nanos' => Money::class,
            'est_max_nanos' => Money::class,
            'provider_cost_nanos' => Money::class,
            'charged_nanos' => Money::class,
            'image_url_expires_at' => 'datetime',
            'submitted_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<CreditTransaction, $this> */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }
}
