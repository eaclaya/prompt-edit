<?php

use Illuminate\Support\Facades\DB;

it('runs the suite against the Postgres testing database', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(DB::connection()->getDatabaseName())->toBe('testing');
});

it('uses Postgres and Redis outside tests', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)
        ->toContain("DB_CONNECTION=pgsql\n")
        ->toContain("QUEUE_CONNECTION=redis\n")
        ->toContain("CACHE_STORE=redis\n");
});
