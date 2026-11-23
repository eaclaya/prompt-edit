<?php

namespace App\Http\Middleware;

use App\Contracts\Ledger;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Owned by S3. Shares the `balance` Inertia prop on every page
 * (SharedBalance in resources/js/types/credits.ts): null for guests.
 */
class ShareBalance
{
    public function __construct(private readonly Ledger $ledger) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        Inertia::share('balance', function () use ($request): ?array {
            $user = $request->user();

            if (! $user instanceof User) {
                return null;
            }

            $user = $user->fresh() ?? $user;

            return [
                'balance' => $user->balance_nanos->toDecimal(),
                'available' => $this->ledger->available($user)->toDecimal(),
            ];
        });

        return $next($request);
    }
}
