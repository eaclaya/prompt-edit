<?php

namespace App\Models;

use App\Enums\LedgerEntryType;
use App\Support\Money;
use Database\Factories\CreditTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One append-only ledger row. Only the Ledger (S3) writes these.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $generation_id
 * @property LedgerEntryType $type
 * @property Money $amount_nanos signed
 * @property Money $balance_after_nanos
 * @property string|null $idempotency_key
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Generation|null $generation
 */
class CreditTransaction extends Model
{
    /** @use HasFactory<CreditTransactionFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => LedgerEntryType::class,
            'amount_nanos' => Money::class,
            'balance_after_nanos' => Money::class,
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Generation, $this> */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }
}
