<?php

namespace App\Models;

use App\Enums\LedgerType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One balance movement. Only App\Billing\Ledger creates these.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $generation_id
 * @property LedgerType $type
 * @property int $amount_nano
 * @property int|null $provider_cost_nano
 * @property string|null $idempotency_key
 * @property string|null $description
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Unguarded]
class LedgerEntry extends Model
{
    protected function casts(): array
    {
        return [
            'type' => LedgerType::class,
            'amount_nano' => 'integer',
            'provider_cost_nano' => 'integer',
            'occurred_at' => 'datetime',
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
