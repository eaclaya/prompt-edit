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

    /** @var literal-string */
    private const string UTC_DAY = "(generations.completed_at AT TIME ZONE 'UTC')::date";

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

    /** @return list<UserTotals> users with billed generations in the range, biggest spender first */
    public function byUser(ReportRange $range): array
    {
        return array_values($this->billed($range)
            ->leftJoin('users', 'users.id', '=', 'generations.user_id')
            ->select('generations.user_id', 'users.name', 'users.email')
            ->selectRaw(self::aggregates())
            ->groupBy('generations.user_id', 'users.name', 'users.email')
            ->orderByDesc('charged_nanos')
            ->orderBy('generations.user_id')
            ->toBase()
            ->get()
            ->map(function (object $row) {
                /** @var object{user_id: int|null, name: string|null, email: string|null} $row */
                return new UserTotals(
                    $row->user_id,
                    $row->name ?? 'Deleted user',
                    $row->email ?? '',
                    self::totals($row),
                );
            })
            ->all());
    }

    /** @return Builder<Generation> */
    private function billed(ReportRange $range): Builder
    {
        return Generation::query()
            ->where('generations.status', GenerationStatus::Succeeded)
            // With an explicit offset, so the session's time zone can't shift the bounds.
            ->where('generations.completed_at', '>=', $range->start->format(self::INSTANT))
            ->where('generations.completed_at', '<', $range->endExclusive()->format(self::INSTANT));
    }

    /** @return literal-string */
    private static function aggregates(): string
    {
        return 'count(*) as generations, '
            .'coalesce(sum(generations.provider_cost_nanos), 0) as provider_cost_nanos, '
            .'coalesce(sum(generations.charged_nanos), 0) as charged_nanos';
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
