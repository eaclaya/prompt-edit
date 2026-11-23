<?php

namespace App\Http\Controllers;

use App\Providers\AdminServiceProvider;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/** Owned by S7, which also authorises admins (users.is_admin). Page: admin/Report (see resources/js/types/admin.ts). */
class AdminReportController extends Controller
{
    public function __invoke(): Response
    {
        Gate::authorize(AdminServiceProvider::VIEW_REPORT);

        return Inertia::render('admin/Report');
    }
}
