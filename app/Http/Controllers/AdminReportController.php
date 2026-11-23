<?php

namespace App\Http\Controllers;

use App\Providers\AdminServiceProvider;
use App\Reports\AdminReport;
use App\Reports\ReportRange;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** Owned by S7, which also authorises admins (users.is_admin). Page: admin/Report (see resources/js/types/admin.ts). */
class AdminReportController extends Controller
{
    public const int DEFAULT_DAYS = 30;

    public const int MAX_DAYS = 366;

    public function __invoke(Request $request, AdminReport $report): Response
    {
        Gate::authorize(AdminServiceProvider::VIEW_REPORT);

        return Inertia::render('admin/Report', $report->build($this->range($request)));
    }

    private function range(Request $request): ReportRange
    {
        /** @var array{start?: string|null, end?: string|null} $input */
        $input = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);

        $end = $input['end'] ?? CarbonImmutable::now('UTC')->toDateString();
        $start = $input['start'] ?? null;

        if ($start !== null && $end < $start) {
            throw ValidationException::withMessages(['end' => 'The end date must be on or after the start date.']);
        }

        $range = $start === null ? ReportRange::endingOn($end, self::DEFAULT_DAYS) : new ReportRange($start, $end);

        if ($range->length() > self::MAX_DAYS) {
            throw ValidationException::withMessages(['end' => 'The report covers at most '.self::MAX_DAYS.' days.']);
        }

        return $range;
    }
}
