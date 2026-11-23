<?php

namespace App\Http\Controllers;

use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Owned by S6. Pages: generations/Index, generations/Create, generations/Show (see resources/js/types/generations.ts). */
class GenerationController extends Controller
{
    public const int PER_PAGE = 12;

    public function index(Request $request): Response
    {
        $generations = $this->user($request)->generations()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return Inertia::render('generations/Index', [
            'generations' => GenerationResource::collection($generations),
        ]);
    }

    public function create(): Response
    {
        abort(501, 'Not implemented yet (S6).');
    }

    public function show(Generation $generation): Response
    {
        abort(501, 'Not implemented yet (S6).');
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
