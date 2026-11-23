<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks Pixelforge-Signature: t=<unix>,v1=hex(HMAC-SHA256("{t}.{raw body}", whsec)).
 * Constant-time compare, timestamp within the configured tolerance (300 s), otherwise 400.
 * Several v1 values are accepted (any match wins) so a secret rotation does not drop deliveries.
 */
class VerifyPixelforgeSignature
{
    public const HEADER = 'Pixelforge-Signature';

    public function handle(Request $request, Closure $next): Response
    {
        $failure = $this->failure($request);

        if ($failure !== null) {
            Log::warning("Pixelforge webhook rejected: {$failure}.", [
                'event_id' => $request->header('Pixelforge-Event-Id'),
                'ip' => $request->ip(),
            ]);

            return response()->json(['error' => 'invalid_signature'], 400);
        }

        return $next($request);
    }

    private function failure(Request $request): ?string
    {
        $secret = (string) config('pixelforge.webhook_secret');

        if ($secret === '') {
            return 'no webhook secret configured';
        }

        [$timestamp, $signatures] = $this->parse((string) $request->header(self::HEADER, ''));

        if ($timestamp === null || $signatures === []) {
            return 'missing or malformed signature header';
        }

        if (abs(now()->getTimestamp() - $timestamp) > (int) config('pixelforge.webhook_tolerance', 300)) {
            return 'timestamp outside the tolerance';
        }

        $expected = hash_hmac('sha256', "{$timestamp}.{$request->getContent()}", $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return null;
            }
        }

        return 'signature mismatch';
    }

    /** @return array{0: int|null, 1: list<string>} */
    private function parse(string $header): array
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't' && ctype_digit($value)) {
                $timestamp = (int) $value;
            } elseif ($key === 'v1' && $value !== '') {
                $signatures[] = strtolower($value);
            }
        }

        return [$timestamp, $signatures];
    }
}
