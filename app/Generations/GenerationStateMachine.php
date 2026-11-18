<?php

namespace App\Generations;

use App\Billing\Ledger;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Pixelforge\GenerationPayload;
use App\Pricing\CostCalculator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * The single place a generation's status and money change.
 *
 * Webhooks, polling and the submit job all report what Pixelforge says
 * through apply(). Under a row lock, a report is applied only if it moves
 * the generation forward; a final status is never left. Settling a final
 * status writes ledger entries that are themselves unique per generation.
 * So duplicate, late or out-of-order reports can't change state backwards
 * or move money twice.
 */
final readonly class GenerationStateMachine
{
    public function __construct(
        private Ledger $ledger,
        private CostCalculator $calculator,
    ) {}

    /**
     * Apply Pixelforge's view of a generation.
     *
     * @return Generation|null the updated generation, or null if it isn't ours
     */
    public function apply(GenerationPayload $payload): ?Generation
    {
        return DB::transaction(function () use ($payload): ?Generation {
            $generation = $this->findForUpdate($payload);

            if ($generation === null) {
                return null;
            }

            // A webhook can arrive before the submit job has stored the id.
            $generation->provider_id ??= $payload->id;
            $generation->last_synced_at = now();

            if ($generation->status->canAdvanceTo($payload->status)) {
                $this->advance($generation, $payload);
            }

            $generation->save();

            return $generation;
        });
    }

    /**
     * Fail a generation that Pixelforge definitively rejected (or never
     * accepted). Nothing exists at Pixelforge, so the user owes nothing.
     */
    public function failLocally(Generation $generation, string $errorCode, string $message): Generation
    {
        return DB::transaction(function () use ($generation, $errorCode, $message): Generation {
            $locked = Generation::whereKey($generation->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== GenerationStatus::Pending) {
                return $locked;
            }

            $locked->fill([
                'status' => GenerationStatus::Failed,
                'error_code' => $errorCode,
                'error_message' => $message,
                'price_nano' => 0,
                'completed_at' => now(),
            ])->save();

            $this->ledger->release($locked);

            return $locked;
        });
    }

    private function findForUpdate(GenerationPayload $payload): ?Generation
    {
        $generation = Generation::where('provider_id', $payload->id)->lockForUpdate()->first();

        if ($generation === null && $payload->localId() !== null) {
            $generation = Generation::whereKey($payload->localId())
                ->where(fn ($q) => $q->whereNull('provider_id')->orWhere('provider_id', $payload->id))
                ->lockForUpdate()
                ->first();
        }

        return $generation;
    }

    private function advance(Generation $generation, GenerationPayload $payload): void
    {
        $generation->status = $payload->status;
        $generation->rates = $payload->rates ?? $generation->rates;
        $generation->provider_created_at = $payload->createdAt ?? $generation->provider_created_at;
        $generation->started_at = $payload->startedAt ?? $generation->started_at;
        $generation->completed_at = $payload->completedAt ?? $generation->completed_at;

        match ($payload->status) {
            GenerationStatus::Succeeded => $this->settleSuccess($generation, $payload),
            GenerationStatus::Failed => $this->settleFailure($generation, $payload),
            default => null,
        };
    }

    private function settleSuccess(Generation $generation, GenerationPayload $payload): void
    {
        if ($payload->usage === null || $generation->rates === null) {
            throw new LogicException("Succeeded generation {$payload->id} has no usage or rates.");
        }

        $cost = $this->calculator->cost($payload->usage, $generation->rates);
        $price = $this->calculator->price($cost);

        if ($price > $generation->est_price_max_nano) {
            // Can't happen within Pixelforge's documented ranges. We still
            // charge the actual price, but want to hear about it.
            Log::warning('Generation cost more than its estimated maximum.', [
                'generation' => $generation->id,
                'price_nano' => $price,
                'est_price_max_nano' => $generation->est_price_max_nano,
            ]);
        }

        $generation->fill([
            'input_text_tokens' => $payload->usage->inputText,
            'input_image_tokens' => $payload->usage->inputImage,
            'output_tokens' => $payload->usage->output,
            'reasoning_tokens' => $payload->usage->reasoning,
            'provider_cost_nano' => $cost,
            'price_nano' => $price,
            'image_url' => $payload->imageUrl,
            'image_url_expires_at' => $payload->imageUrlExpiresAt,
            'completed_at' => $payload->completedAt ?? now(),
        ]);

        // Save first: the ledger locks the user row and reads the generation.
        $generation->save();

        $this->ledger->release($generation);
        $this->ledger->charge($generation, $price, $cost, $generation->completed_at);
    }

    private function settleFailure(Generation $generation, GenerationPayload $payload): void
    {
        $generation->fill([
            'error_code' => $payload->errorCode ?? 'generation_failed',
            'error_message' => $payload->errorMessage,
            'price_nano' => 0,
            'completed_at' => $payload->completedAt ?? now(),
        ]);

        $generation->save();

        $this->ledger->release($generation);
    }
}
