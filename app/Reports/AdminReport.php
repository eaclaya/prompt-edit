<?php

namespace App\Reports;

use App\Pixelforge\Exceptions\PixelforgeException;
use App\Support\Money;
use Illuminate\Support\Facades\Log;

/** The operator report: our spend per day and per user, reconciled with Pixelforge's /v1/usage. */
final readonly class AdminReport
{
    public function __construct(
        private SpendReport $spend,
        private PixelforgeUsage $usage,
    ) {}

    /**
     * The admin/Report props (resources/js/types/admin.ts), money as decimal strings.
     *
     * @return array<string, mixed>
     */
    public function build(ReportRange $range): array
    {
        $days = $this->spend->byDay($range);
        [$pixelforge, $usageError] = $this->pixelforgeCosts($range);

        $rows = [];
        $totals = ReportTotals::zero();
        $pixelforgeTotal = $pixelforge === null ? null : Money::zero();

        foreach ($days as $date => $day) {
            $totals = $totals->add($day);
            $theirs = $pixelforge === null ? null : ($pixelforge[$date] ?? Money::zero());
            $pixelforgeTotal = $theirs === null ? null : $pixelforgeTotal?->add($theirs);
            $rows[] = ['date' => $date, ...$day->toArray(), ...self::reconcile($day->providerCost, $theirs)];
        }

        $reconciled = self::reconcile($totals->providerCost, $pixelforgeTotal);

        return [
            'filters' => $range->toArray(),
            'days' => $rows,
            'users' => array_map(fn (UserTotals $user) => $user->toArray(), $this->spend->byUser($range)),
            'totals' => [
                ...$totals->toArray(),
                'pixelforge_cost' => $reconciled['pixelforge_cost'],
                'difference' => $reconciled['difference'],
            ],
            'usage_error' => $usageError,
        ];
    }

    /** @return array{0: array<string, Money>|null, 1: string|null} */
    private function pixelforgeCosts(ReportRange $range): array
    {
        try {
            return [$this->usage->costByDay($range), null];
        } catch (PixelforgeException $e) {
            Log::warning('Admin report: GET /v1/usage failed.', ['code' => $e->errorCode, 'message' => $e->getMessage()]);

            return [null, "Pixelforge usage could not be loaded ({$e->errorCode}): {$e->getMessage()}"];
        }
    }

    /** @return array{pixelforge_cost: string|null, difference: string|null, mismatch: bool} */
    private static function reconcile(Money $ours, ?Money $theirs): array
    {
        if ($theirs === null) {
            return ['pixelforge_cost' => null, 'difference' => null, 'mismatch' => false];
        }

        $difference = $ours->subtract($theirs);

        return [
            'pixelforge_cost' => $theirs->toDecimal(),
            'difference' => $difference->toDecimal(),
            'mismatch' => ! $difference->isZero(),
        ];
    }
}
