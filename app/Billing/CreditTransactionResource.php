<?php

namespace App\Billing;

use App\Enums\LedgerEntryType;
use App\Models\CreditTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * CreditTransactionRow in resources/js/types/credits.ts.
 *
 * @mixin CreditTransaction
 */
class CreditTransactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'amount' => $this->amount_nanos->toDecimal(),
            'balance_after' => $this->balance_after_nanos->toDecimal(),
            'generation_id' => $this->generation_id,
            'description' => match ($this->type) {
                LedgerEntryType::Welcome => 'Welcome credit',
                LedgerEntryType::Purchase => 'Purchased credits',
                LedgerEntryType::Hold => 'Hold for generation',
                LedgerEntryType::HoldRelease => 'Hold released',
                LedgerEntryType::Charge => 'Generation charge',
            },
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
