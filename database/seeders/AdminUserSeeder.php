<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** The operator account (users.is_admin) that can open /admin/report. */
class AdminUserSeeder extends Seeder
{
    use SeedsAccount;

    public function run(): void
    {
        $this->seedAccount('admin', admin: true);
    }
}
