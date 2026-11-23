<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/** Owned by S4. Returns EstimateResponse (resources/js/types/generations.ts) as JSON. */
class EstimateController extends Controller
{
    public function __invoke(): JsonResponse
    {
        abort(501, 'Not implemented yet (S4).');
    }
}
