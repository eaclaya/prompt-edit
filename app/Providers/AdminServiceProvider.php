<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** S7: who may see the operator report. */
class AdminServiceProvider extends ServiceProvider
{
    public const string VIEW_REPORT = 'view-admin-report';

    public function boot(): void
    {
        Gate::define(self::VIEW_REPORT, fn (User $user): bool => $user->is_admin);
    }
}
