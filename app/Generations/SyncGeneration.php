<?php

namespace App\Generations;

use App\Contracts\CostCalculator;
use App\Contracts\GenerationSync;
use App\Contracts\Ledger;
use App\Contracts\Markup;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\Data\ProviderGeneration;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * The one path for every provider update (POST response, webhooks, the reconcile poller).
 * Locks the local row, applies the update only if the status moves forward, and settles
 * (success) or releases (failure) the hold in the same transaction. Safe to call any number of times.
 */
final class SyncGeneration implements GenerationSync
{
    public function __construct(
        private readonly Ledger $ledger,
        private readonly CostCalculator $calculator,
        private readonly Markup $markup,
    ) {}

    public function apply(ProviderGeneration $remote): ?Generation
    {
        $id = $this->match($remote);

        if ($id === null) {
            Log::info('Pixelforge update for an unknown generation ignored.', ['provider_id' => $remote->id, 'local_id' => $remote->localId()]);

            return null;
        }

        return DB::transaction(function () use ($id, $remote) {
            /** @var Generation $generation */
            $generation = Generation::query()->whereKey($id)->lockForUpdate()->firstOrFail();

            // Known facts from the provider are recorded even when the status can't move (e.g. a late POST response).
            $generation->provider_id ??= $remote->id;
            $generation->rates ??= $this->rates($remote);

            if (StatusTransitions::allows($generation->status, $remote->status)) {
                $this->advance($generation, $remote);
            } elseif ($generation->status !== $remote->status) {
                Log::info('Out-of-order Pixelforge update ignored.', ['generation' => $generation->id, 'local' => $generation->status->value, 'remote' => $remote->status->value]);
            }

            $generation->save();

            return $generation;
        });
    }

    /** metadata.local_id first (it may arrive before we know the gen_ id), then provider_id. */
    private function match(ProviderGeneration $remote): ?string
    {
        $localId = $remote->localId();

        if ($localId !== null) {
            $id = Generation::query()
                ->whereKey($localId)
                ->where(fn ($q) => $q->whereNull('provider_id')->orWhere('provider_id', $remote->id))
                ->value('id');

            if (is_string($id)) {
                return $id;
            }
        }

        $id = Generation::query()->where('provider_id', $remote->id)->value('id');

        return is_string($id) ? $id : null;
    }

    private function advance(Generation $generation, ProviderGeneration $remote): void
    {
        $generation->status = $remote->status;
        $generation->submitted_at ??= Carbon::instance($remote->createdAt);

        if ($remote->status !== GenerationStatus::Queued) {
            $generation->started_at ??= Carbon::instance($remote->startedAt ?? $remote->completedAt ?? now());
        }

        match ($remote->status) {
            GenerationStatus::Succeeded => $this->succeed($generation, $remote),
            GenerationStatus::Failed => $this->fail($generation, $remote),
            default => null,
        };
    }

    private function succeed(Generation $generation, ProviderGeneration $remote): void
    {
        if ($remote->usage === null) {
            throw new UnexpectedValueException("Pixelforge reported generation [{$remote->id}] succeeded without usage.");
        }

        $cost = $this->calculator->providerCost($remote->usage, $remote->rates);
        $charge = $this->markup->apply($cost);

        $generation->fill([
            'usage' => $remote->usage->toArray(),
            'image_url' => $remote->imageUrl,
            'image_url_expires_at' => $remote->imageUrlExpiresAt,
            'provider_cost_nanos' => $cost,
            'charged_nanos' => $charge,
            'error_code' => null,
            'error_message' => null,
            'completed_at' => $remote->completedAt ?? now(),
        ]);

        if ($charge->isGreaterThan($generation->est_max_nanos)) {
            Log::warning('Charge exceeds the held maximum (rates changed?); charging the true amount.', [
                'generation' => $generation->id, 'charge' => $charge->toDecimal(), 'held' => $generation->est_max_nanos->toDecimal(),
            ]);
        }

        $this->ledger->settle($generation, $charge);
    }

    private function fail(Generation $generation, ProviderGeneration $remote): void
    {
        $generation->fill([
            'error_code' => $remote->errorCode ?? 'generation_failed',
            'error_message' => $remote->errorMessage,
            'provider_cost_nanos' => Money::zero(),
            'charged_nanos' => Money::zero(),
            'completed_at' => $remote->completedAt ?? now(),
        ]);

        $this->ledger->release($generation);
    }

    /** @return array<string, string> */
    private function rates(ProviderGeneration $remote): array
    {
        /** @var array<string, string>|null $raw */
        $raw = $remote->raw['rates'] ?? null;

        return $raw ?? $remote->rates->toArray();
    }
}
