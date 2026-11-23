<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

/** Owned by S4. Validates, holds the max user price, creates a pending generation, dispatches SubmitGeneration. */
class StoreGenerationController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        abort(501, 'Not implemented yet (S4).');
    }
}
