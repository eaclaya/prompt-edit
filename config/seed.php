<?php

/*
 * Accounts created by `php artisan db:seed` (database/seeders). Passwords come from the
 * environment only: the seeder refuses to create an account without one.
 */
return [

    'demo' => [
        'name' => env('SEED_DEMO_NAME', 'Demo User'),
        'email' => env('SEED_DEMO_EMAIL', 'demo@pixelforge.test'),
        'password' => env('SEED_DEMO_PASSWORD'),
    ],

    // Pre-fill the login form with the demo account (never the admin). The live site publishes
    // these credentials for reviewers; leave it off anywhere they are private.
    'prefill_login' => (bool) env('LOGIN_PREFILL_DEMO', false),

    'admin' => [
        'name' => env('SEED_ADMIN_NAME', 'Studio Operator'),
        'email' => env('SEED_ADMIN_EMAIL', 'admin@pixelforge.test'),
        'password' => env('SEED_ADMIN_PASSWORD'),
    ],

];
