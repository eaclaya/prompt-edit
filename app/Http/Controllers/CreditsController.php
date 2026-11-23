<?php

namespace App\Http\Controllers;

use App\Billing\CreditPack;
use App\Billing\CreditTransactionResource;
use App\Contracts\Ledger;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Owned by S3. Page: credits/Index (see resources/js/types/credits.ts). */
class CreditsController extends Controller
{
    public function __construct(private readonly Ledger $ledger) {}

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('credits/Index', [
            'balance' => $user->balance_nanos->toDecimal(),
            'available' => $this->ledger->available($user)->toDecimal(),
            'packs' => array_map(fn (CreditPack $pack) => $pack->toArray(), CreditPack::cases()),
            'transactions' => CreditTransactionResource::collection(
                $user->creditTransactions()->latest('id')->paginate(25),
            ),
        ]);
    }

    /** Fake checkout: credits the pack at once. The client's token makes a double-click credit once. */
    public function purchase(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'pack' => ['required', 'string', Rule::enum(CreditPack::class)],
            'token' => ['required', 'string', 'max:100'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $this->ledger->purchase($user, CreditPack::from($validated['pack'])->amount(), $validated['token']);

        return back();
    }
}
