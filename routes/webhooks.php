<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Webhook routes are loaded without the "web" middleware group: no session,
| cookies or CSRF. Requests are authenticated by their signature instead.
*/

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge');
