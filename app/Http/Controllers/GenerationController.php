<?php

namespace App\Http\Controllers;

use App\Models\Generation;
use Inertia\Response;

/** Owned by S6. Pages: generations/Index, generations/Create, generations/Show (see resources/js/types/generations.ts). */
class GenerationController extends Controller
{
    public function index(): Response
    {
        abort(501, 'Not implemented yet (S6).');
    }

    public function create(): Response
    {
        abort(501, 'Not implemented yet (S6).');
    }

    public function show(Generation $generation): Response
    {
        abort(501, 'Not implemented yet (S6).');
    }
}
