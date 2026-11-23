<?php

namespace App\Http\Controllers;

use App\Http\Middleware\VerifyPixelforgeSignature;
use App\Jobs\ProcessWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Owned by S5. Outside the web group: no session, auth or CSRF.
 * Verifies the signature on the raw body, records the event once, queues processing, returns 2xx fast.
 */
class PixelforgeWebhookController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [new Middleware(VerifyPixelforgeSignature::class)];
    }

    public function __invoke(Request $request): JsonResponse
    {
        $event = json_decode($request->getContent(), true);

        if (! is_array($event) || ! is_string($event['id'] ?? null) || ! is_string($event['type'] ?? null)) {
            return response()->json(['error' => 'malformed_event'], 400);
        }

        if ($event['type'] === 'ping') {
            return response()->json(['received' => true]);
        }

        $object = $event['data']['object'] ?? null;

        $inserted = DB::table('webhook_events')->insertOrIgnore([
            'event_id' => $event['id'],
            'type' => $event['type'],
            'generation_provider_id' => is_array($object) && is_string($object['id'] ?? null) ? $object['id'] : null,
            'payload' => json_encode($event, JSON_THROW_ON_ERROR),
            'received_at' => now(),
        ]);

        if ($inserted === 0) {
            return response()->json(['received' => true, 'duplicate' => true]);
        }

        try {
            Bus::dispatch(new ProcessWebhookEvent($event['id']));
        } catch (Throwable $e) {
            // Could not queue it: forget the event so Pixelforge's retry is not swallowed as a duplicate.
            DB::table('webhook_events')->where('event_id', $event['id'])->delete();

            throw $e;
        }

        return response()->json(['received' => true]);
    }
}
