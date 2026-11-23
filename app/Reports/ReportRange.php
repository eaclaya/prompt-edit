<?php

namespace App\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/** An inclusive range of UTC calendar days. */
final readonly class ReportRange
{
    public CarbonImmutable $start;

    public CarbonImmutable $end;

    public function __construct(string $start, string $end)
    {
        $this->start = CarbonImmutable::createFromFormat('!Y-m-d', $start, 'UTC') ?: throw new InvalidArgumentException("Invalid date [{$start}].");
        $this->end = CarbonImmutable::createFromFormat('!Y-m-d', $end, 'UTC') ?: throw new InvalidArgumentException("Invalid date [{$end}].");

        if ($this->end->lt($this->start)) {
            throw new InvalidArgumentException("The range ends [{$end}] before it starts [{$start}].");
        }
    }

    /** The $days UTC days ending on (and including) $end. */
    public static function endingOn(string $end, int $days): self
    {
        $last = CarbonImmutable::parse($end, 'UTC');

        return new self($last->subDays($days - 1)->toDateString(), $last->toDateString());
    }

    public function length(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    /** The first instant after the range: completed_at must be before this. */
    public function endExclusive(): CarbonImmutable
    {
        return $this->end->addDay();
    }

    /** @return list<string> every day in the range, YYYY-MM-DD, oldest first */
    public function dates(): array
    {
        $dates = [];
        for ($day = $this->start; $day->lte($this->end); $day = $day->addDay()) {
            $dates[] = $day->toDateString();
        }

        return $dates;
    }

    /** @return array{start: string, end: string} */
    public function toArray(): array
    {
        return ['start' => $this->start->toDateString(), 'end' => $this->end->toDateString()];
    }
}
