<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** A regular user for trying the studio: verified, with the $1.00 welcome credit. */
class DemoUserSeeder extends Seeder
{
    use SeedsAccount;

    public function run(): void
    {
        $this->seedAccount('demo', admin: false);
    }
}
