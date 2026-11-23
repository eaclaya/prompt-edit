<?php

namespace App\Reports;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;

/**
 * Our own records of billed generations. Only succeeded generations are billed (failures cost $0
 * on both sides), and they are grouped by the UTC day of completed_at, like GET /v1/usage.
 */
final class SpendReport
{
    private const string INSTANT = 'Y-m-d H:i:sP';

    private const string UTC_DAY = "(completed_at AT TIME ZONE 'UTC')::date";

    /** @return array<string, ReportTotals> keyed by every date in the range, oldest first */
    public function byDay(ReportRange $range): array
    {
        $rows = $this->billed($range)
            ->selectRaw(self::UTC_DAY.'::text as day')
            ->selectRaw(self::aggregates())
            ->groupByRaw(self::UTC_DAY)
            ->toBase()
            ->get()
            ->keyBy('day');

        $days = [];
        foreach ($range->dates() as $date) {
            $row = $rows->get($date);
            $days[$date] = $row === null ? ReportTotals::zero() : self::totals($row);
        }

        return $days;
    }

    /** @return Builder<Generation> */
    private function billed(ReportRange $range): Builder
    {
        return Generation::query()
            ->where('status', GenerationStatus::Succeeded)
            // With an explicit offset, so the session's time zone can't shift the bounds.
            ->where('completed_at', '>=', $range->start->format(self::INSTANT))
            ->where('completed_at', '<', $range->endExclusive()->format(self::INSTANT));
    }

    private static function aggregates(): string
    {
        return 'count(*) as generations, '
            .'coalesce(sum(provider_cost_nanos), 0) as provider_cost_nanos, '
            .'coalesce(sum(charged_nanos), 0) as charged_nanos';
    }

    private static function totals(object $row): ReportTotals
    {
        /** @var object{generations: int|string, provider_cost_nanos: int|string, charged_nanos: int|string} $row */
        return new ReportTotals(
            (int) $row->generations,
            Money::ofNanos((int) $row->provider_cost_nanos),
            Money::ofNanos((int) $row->charged_nanos),
        );
    }
}
