<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * Owned by S5. Outside the web group: no session, auth or CSRF.
 * Verifies the signature on the raw body, records the event once, queues processing, returns 2xx fast.
 */
class PixelforgeWebhookController extends Controller
{
    public function __invoke(): Response
    {
        abort(501, 'Not implemented yet (S5).');
    }
}
