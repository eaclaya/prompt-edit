<?php

namespace App\Generations;

use App\Billing\InsufficientCredits;
use App\Billing\Ledger;
use App\Enums\Quality;
use App\Enums\Size;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Pricing\CostEstimator;
use App\Pricing\PricingCatalog;
use Illuminate\Support\Facades\DB;

/**
 * Records a new generation and reserves its maximum price, then queues the
 * submission to Pixelforge once that is committed.
 */
final readonly class CreateGeneration
{
    public function __construct(
        private PricingCatalog $pricing,
        private CostEstimator $estimator,
        private Ledger $ledger,
    ) {}

    /**
     * @param  array<int, string>  $referenceImages
     *
     * @throws InsufficientCredits
     */
    public function __invoke(User $user, string $prompt, Size $size, Quality $quality, array $referenceImages): Generation
    {
        $estimate = $this->estimator->estimate($prompt, $quality, $size, count($referenceImages), $this->pricing->current());

        return DB::transaction(function () use ($user, $prompt, $size, $quality, $referenceImages, $estimate): Generation {
            $generation = $user->generations()->create([
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => array_values($referenceImages),
                'est_cost_min_nano' => $estimate->costMinNano,
                'est_cost_max_nano' => $estimate->costMaxNano,
                'est_price_min_nano' => $estimate->priceMinNano,
                'est_price_max_nano' => $estimate->priceMaxNano,
            ]);

            // Throws InsufficientCredits, rolling back the generation too.
            $this->ledger->hold($generation);

            SubmitGeneration::dispatch($generation->id)->afterCommit();

            return $generation;
        });
    }
}
