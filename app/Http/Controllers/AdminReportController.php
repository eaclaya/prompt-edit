<?php

namespace App\Http\Controllers;

use Inertia\Response;

/** Owned by S7, which also authorises admins (users.is_admin). Page: admin/Report (see resources/js/types/admin.ts). */
class AdminReportController extends Controller
{
    public function __invoke(): Response
    {
        abort(501, 'Not implemented yet (S7).');
    }
}
