<?php

namespace App\Reports;

use App\Pixelforge\PixelforgeClient;
use App\Support\Money;

/** What Pixelforge says it billed us, per UTC day of completion (GET /v1/usage). */
final readonly class PixelforgeUsage
{
    /** GET /v1/usage covers at most this many days per call (inclusive). */
    public const int MAX_DAYS_PER_CALL = 92;

    public function __construct(private PixelforgeClient $client) {}

    /**
     * @return array<string, Money> cost_usd keyed by date
     *
     * @throws \App\Pixelforge\Exceptions\PixelforgeException
     */
    public function costByDay(ReportRange $range): array
    {
        $costs = [];
        foreach ($range->chunks(self::MAX_DAYS_PER_CALL) as $chunk) {
            $usage = $this->client->getUsage($chunk->start->toDateString(), $chunk->end->toDateString());
            foreach ($usage->days as $day) {
                if ($day->date !== null) {
                    $costs[$day->date] = $day->cost;
                }
            }
        }

        return $costs;
    }
}
