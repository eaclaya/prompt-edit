<?php

namespace App\Http\Controllers;

use App\Contracts\CostEstimator;
use App\Contracts\Ledger;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/** Owned by S4. Returns EstimateResponse (resources/js/types/generations.ts) as JSON. Holds nothing. */
class EstimateController extends Controller
{
    public function __invoke(StoreGenerationRequest $request, CostEstimator $estimator, Ledger $ledger): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $estimate = $estimator->estimate($request->estimateInput());
        $available = $ledger->available($user);

        return response()->json([
            'estimate' => $estimate->toArray(),
            'available' => $available->toDecimal(),
            'can_afford' => $available->isGreaterThanOrEqualTo($estimate->userMax),
        ]);
    }
}
