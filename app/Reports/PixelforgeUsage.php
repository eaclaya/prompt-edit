<?php

namespace App\Reports;

use App\Pixelforge\PixelforgeClient;
use App\Support\Money;

/** What Pixelforge says it billed us, per UTC day of completion (GET /v1/usage). */
final readonly class PixelforgeUsage
{
    public function __construct(private PixelforgeClient $client) {}

    /**
     * @return array<string, Money> cost_usd keyed by date
     *
     * @throws \App\Pixelforge\Exceptions\PixelforgeException
     */
    public function costByDay(ReportRange $range): array
    {
        $costs = [];
        $usage = $this->client->getUsage($range->start->toDateString(), $range->end->toDateString());
        foreach ($usage->days as $day) {
            if ($day->date !== null) {
                $costs[$day->date] = $day->cost;
            }
        }

        return $costs;
    }
}
