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

    'admin' => [
        'name' => env('SEED_ADMIN_NAME', 'Studio Operator'),
        'email' => env('SEED_ADMIN_EMAIL', 'admin@pixelforge.test'),
        'password' => env('SEED_ADMIN_PASSWORD'),
    ],

];
