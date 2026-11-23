<?php

namespace App\Generations;

use App\Enums\GenerationStatus;

/**
 * The forward-only state machine: pending → queued → processing → succeeded | failed.
 * An update may skip steps (a webhook can overtake the POST response) but never goes back,
 * and succeeded / failed are terminal.
 */
final class StatusTransitions
{
    public static function allows(GenerationStatus $from, GenerationStatus $to): bool
    {
        return ! $from->isFinal() && self::rank($to) > self::rank($from);
    }

    private static function rank(GenerationStatus $status): int
    {
        return match ($status) {
            GenerationStatus::Pending => 0,
            GenerationStatus::Queued => 1,
            GenerationStatus::Processing => 2,
            GenerationStatus::Succeeded, GenerationStatus::Failed => 3,
        };
    }
}
