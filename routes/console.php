<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Fallback for missed webhooks (S5).
Schedule::command('generations:reconcile')->everyMinute()->withoutOverlapping()->onOneServer();
