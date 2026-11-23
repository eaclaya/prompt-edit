<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Slot reserved by S1 in the web group, after HandleInertiaRequests. Owned by S3,
 * which makes it share the `balance` Inertia prop (SharedBalance in resources/js/types/credits.ts).
 * Until then it does nothing.
 */
class ShareBalance
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }
}
