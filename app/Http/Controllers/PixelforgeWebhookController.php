<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhookEvent;
use App\Models\WebhookEvent;
use App\Pixelforge\SignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Pixelforge webhooks. Pixelforge expects a 2xx within 10 seconds,
 * so this only verifies, stores the event once by its id, and queues it.
 */
class PixelforgeWebhookController
{
    public function __invoke(Request $request, SignatureVerifier $verifier): JsonResponse
    {
        $rawBody = $request->getContent();

        if (! $verifier->isValid($request->header('Pixelforge-Signature'), $rawBody)) {
            return response()->json(['error' => 'invalid_signature'], 400);
        }

        $event = json_decode($rawBody, true);

        if (! is_array($event) || ! isset($event['id'], $event['type'])) {
            return response()->json(['error' => 'malformed_event'], 400);
        }

        if ($event['type'] === 'ping') {
            return response()->json(['status' => 'ok']);
        }

        $stored = WebhookEvent::insertOrIgnore([
            'event_id' => (string) $event['id'],
            'type' => (string) $event['type'],
            'provider_generation_id' => $event['data']['object']['id'] ?? null,
            'payload' => $rawBody,
            'attempts_seen' => 1,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($stored === 0) {
            WebhookEvent::where('event_id', $event['id'])->increment('attempts_seen');

            return response()->json(['status' => 'duplicate']);
        }

        ProcessWebhookEvent::dispatch((string) $event['id']);

        return response()->json(['status' => 'queued']);
    }
}
