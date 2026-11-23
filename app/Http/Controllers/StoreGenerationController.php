<?php

namespace App\Http\Controllers;

use App\Contracts\CostEstimator;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use Illuminate\Http\RedirectResponse;

/** Owned by S4. Validates, holds the max user price, creates a pending generation, dispatches SubmitGeneration. */
class StoreGenerationController extends Controller
{
    public function __invoke(StoreGenerationRequest $request, CostEstimator $estimator): RedirectResponse
    {
        $estimate = $estimator->estimate($request->estimateInput());

        Generation::create([
            'user_id' => $request->user()?->id,
            'prompt' => $request->prompt(),
            'size' => $request->size(),
            'quality' => $request->quality(),
            'reference_images' => $request->referenceImages(),
            'est_min_nanos' => $estimate->userMin,
            'est_expected_nanos' => $estimate->userExpected,
            'est_max_nanos' => $estimate->userMax,
        ]);

        return to_route('generations.index');
    }
}
