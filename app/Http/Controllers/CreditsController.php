<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/** Owned by S3. Page: credits/Index (see resources/js/types/credits.ts). */
class CreditsController extends Controller
{
    public function index(): Response
    {
        abort(501, 'Not implemented yet (S3).');
    }

    public function purchase(): RedirectResponse
    {
        abort(501, 'Not implemented yet (S3).');
    }
}
