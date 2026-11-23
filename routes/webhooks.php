<?php

use App\Http\Controllers\PixelforgeWebhookController;
use Illuminate\Support\Facades\Route;

// Loaded outside the web group (bootstrap/app.php): no session, auth or CSRF.
Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)->name('webhooks.pixelforge'); // S5
