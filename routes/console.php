<?php

use App\Jobs\ReconcileGenerations;
use Illuminate\Support\Facades\Schedule;

// Polls stale generations and retries stuck submissions and webhook events.
Schedule::job(new ReconcileGenerations)->everyMinute()->withoutOverlapping()->onOneServer();
