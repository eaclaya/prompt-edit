<?php

namespace App\Billing;

use App\Enums\LedgerType;
use App\Models\Generation;
use App\Models\LedgerEntry;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of balances. Every movement is a ledger entry, and
 * users.balance_nano is kept equal to the sum of a user's entries.
 *
 * Each write is idempotent. Under a lock on the user's row, an entry that
 * already exists (same generation and type, or same idempotency key) is
 * returned instead of being written again. The unique indexes are a second
 * line of defence: a violation is caught inside a savepoint and treated the
 * same way, so a duplicate can never move a balance twice.
 *
 * New entries come back with `wasRecentlyCreated = true`; existing ones with false.
 */
final class Ledger
{
    public function grant(User $user, int $amountNano, string $idempotencyKey, string $description): LedgerEntry
    {
        return $this->record(LedgerType::Grant, $user->id, $amountNano, [
            'idempotency_key' => $idempotencyKey,
            'description' => $description,
        ]);
    }

    public function purchase(User $user, int $amountNano, string $idempotencyKey): LedgerEntry
    {
        return $this->record(LedgerType::Purchase, $user->id, $amountNano, [
            'idempotency_key' => $idempotencyKey,
            'description' => LedgerType::Purchase->label(),
        ]);
    }

    /**
     * Reserve the most a generation can cost. Call inside the transaction
     * that creates the generation, so both are saved or neither is.
     *
     * @throws InsufficientCredits
     */
    public function hold(Generation $generation): LedgerEntry
    {
        $amount = $generation->est_price_max_nano;

        return $this->record(
            LedgerType::Hold,
            $generation->user_id,
            $amount,
            ['generation_id' => $generation->id, 'description' => LedgerType::Hold->label()],
            function (User $user) use ($amount): void {
                if (! $user->canAfford($amount)) {
                    throw new InsufficientCredits($amount, $user->balance_nano);
                }
            },
        );
    }

    /** Return a generation's hold. Null when it never had one. */
    public function release(Generation $generation): ?LedgerEntry
    {
        $hold = $this->entryFor($generation, LedgerType::Hold);

        if ($hold === null) {
            return null;
        }

        return $this->record(LedgerType::Release, $generation->user_id, abs($hold->amount_nano), [
            'generation_id' => $generation->id,
            'description' => LedgerType::Release->label(),
        ]);
    }

    /**
     * Charge the actual price of a succeeded generation.
     * $occurredAt is the provider's completed_at, the day Pixelforge bills.
     */
    public function charge(Generation $generation, int $priceNano, int $providerCostNano, CarbonInterface $occurredAt): LedgerEntry
    {
        return $this->record(LedgerType::Charge, $generation->user_id, $priceNano, [
            'generation_id' => $generation->id,
            'provider_cost_nano' => $providerCostNano,
            'occurred_at' => $occurredAt,
            'description' => LedgerType::Charge->label(),
        ]);
    }

    public function entryFor(Generation $generation, LedgerType $type): ?LedgerEntry
    {
        return LedgerEntry::where('generation_id', $generation->id)->where('type', $type)->first();
    }

    /**
     * Users whose cached balance differs from the sum of their entries.
     * Should always be empty.
     *
     * @return Collection<int, array{user_id: int, balance_nano: int, ledger_sum: int}>
     */
    public function discrepancies(): Collection
    {
        return DB::table('users')
            ->leftJoinSub(
                DB::table('ledger_entries')->selectRaw('user_id, SUM(amount_nano) AS ledger_sum')->groupBy('user_id'),
                'sums',
                'sums.user_id',
                '=',
                'users.id',
            )
            ->selectRaw('users.id, users.balance_nano, COALESCE(sums.ledger_sum, 0) AS ledger_sum')
            ->whereRaw('users.balance_nano <> COALESCE(sums.ledger_sum, 0)')
            ->get()
            ->map(fn (object $row) => [
                'user_id' => (int) $row->id,
                'balance_nano' => (int) $row->balance_nano,
                'ledger_sum' => (int) $row->ledger_sum,
            ]);
    }

    /**
     * @param  int  $magnitude  amount before the type's sign is applied
     * @param  array<string, mixed>  $attributes  must include generation_id or idempotency_key
     * @param  (Closure(User): void)|null  $guard  runs under the user lock before writing
     */
    private function record(LedgerType $type, ?int $userId, int $magnitude, array $attributes, ?Closure $guard = null): LedgerEntry
    {
        if ($magnitude < 0) {
            throw new InvalidArgumentException('Ledger amounts are given as positive magnitudes.');
        }

        if (! isset($attributes['generation_id']) && ! isset($attributes['idempotency_key'])) {
            throw new InvalidArgumentException('Every ledger entry needs a generation or an idempotency key.');
        }

        $amount = $type->sign() * $magnitude;

        return DB::transaction(function () use ($type, $userId, $amount, $attributes, $guard): LedgerEntry {
            // Serialises all writes for this user, so the existence check below is reliable.
            $user = $userId === null ? null : User::whereKey($userId)->lockForUpdate()->first();

            if ($existing = $this->existing($type, $attributes)) {
                return $existing;
            }

            if ($user !== null && $guard !== null) {
                $guard($user);
            }

            try {
                // Nested transaction = savepoint, so a unique violation doesn't
                // abort the caller's transaction on Postgres.
                $entry = DB::transaction(fn () => LedgerEntry::create([
                    'occurred_at' => now(),
                    ...$attributes,
                    'user_id' => $user?->id,
                    'type' => $type,
                    'amount_nano' => $amount,
                ]));
            } catch (UniqueConstraintViolationException) {
                return $this->existing($type, $attributes)
                    ?? throw new LogicException('Ledger entry conflicted but cannot be found.');
            }

            $user?->increment('balance_nano', $amount);

            return $entry;
        });
    }

    /** @param  array<string, mixed>  $attributes */
    private function existing(LedgerType $type, array $attributes): ?LedgerEntry
    {
        return LedgerEntry::query()
            ->when(
                isset($attributes['generation_id']),
                fn (Builder $q) => $q->where('generation_id', $attributes['generation_id'])->where('type', $type),
                fn (Builder $q) => $q->where('idempotency_key', $attributes['idempotency_key']),
            )
            ->first();
    }
}
