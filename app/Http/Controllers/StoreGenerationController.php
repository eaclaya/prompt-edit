<?php

namespace App\Http\Controllers;

use App\Contracts\CostEstimator;
use App\Contracts\Exceptions\InsufficientCredits;
use App\Contracts\Ledger;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Owned by S4. Validates, holds the max user price, creates a pending generation
 * and dispatches SubmitGeneration. The row and its hold commit together, or neither does.
 */
class StoreGenerationController extends Controller
{
    public function __invoke(StoreGenerationRequest $request, CostEstimator $estimator, Ledger $ledger): RedirectResponse
    {
        $estimate = $estimator->estimate($request->estimateInput());

        try {
            $generation = DB::transaction(function () use ($request, $estimate, $ledger) {
                $generation = Generation::create([
                    'user_id' => $request->user()?->id,
                    'prompt' => $request->prompt(),
                    'size' => $request->size(),
                    'quality' => $request->quality(),
                    'reference_images' => $request->referenceImages(),
                    'est_min_nanos' => $estimate->userMin,
                    'est_expected_nanos' => $estimate->userExpected,
                    'est_max_nanos' => $estimate->userMax,
                ]);

                $ledger->hold($generation, $estimate->userMax);

                return $generation;
            });
        } catch (InsufficientCredits $e) {
            throw ValidationException::withMessages(['credits' => $e->getMessage()]);
        }

        SubmitGeneration::dispatch($generation);

        return to_route('generations.index');
    }
}
