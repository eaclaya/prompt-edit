<?php

namespace App\Http\Controllers;

use App\Contracts\Ledger;
use App\Enums\Quality;
use App\Enums\Size;
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

    /** Mirrors the submit validation (S4's StoreGenerationRequest, PLAN.md §7 Phase 3). */
    public const int PROMPT_MAX_LENGTH = 4000;

    public const int REFERENCE_IMAGES_MAX = 4;

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

    public function create(Request $request, Ledger $ledger): Response
    {
        return Inertia::render('generations/Create', [
            'sizes' => array_map(fn (Size $size) => [
                'value' => $size->value,
                'label' => self::sizeLabel($size),
            ], Size::cases()),
            'qualities' => array_map(fn (Quality $quality) => [
                'value' => $quality->value,
                'label' => ucfirst($quality->value),
            ], Quality::cases()),
            'defaults' => [
                'size' => Size::default()->value,
                'quality' => Quality::default()->value,
            ],
            'limits' => [
                'prompt_max_length' => self::PROMPT_MAX_LENGTH,
                'reference_images_max' => self::REFERENCE_IMAGES_MAX,
            ],
            'available' => $ledger->available($this->user($request))->toDecimal(),
        ]);
    }

    public function show(Generation $generation): Response
    {
        abort(501, 'Not implemented yet (S6).');
    }

    private static function sizeLabel(Size $size): string
    {
        [$width, $height] = explode('x', $size->value);

        return ucfirst(strtolower($size->name))." ({$width} × {$height})";
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
