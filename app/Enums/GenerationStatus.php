<?php

namespace App\Enums;

/**
 * Lifecycle of a generation. `pending` is ours alone (saved locally, not yet
 * accepted by Pixelforge); the rest mirror Pixelforge's statuses.
 *
 * Status only ever moves forward: an update is applied when its rank is
 * higher than the current one. Both final states share the top rank, so
 * neither can replace the other.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function rank(): int
    {
        return match ($this) {
            self::Pending => 0,
            self::Queued => 1,
            self::Processing => 2,
            self::Succeeded, self::Failed => 3,
        };
    }

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /** Whether moving from this status to $next is a forward step. */
    public function canAdvanceTo(self $next): bool
    {
        return ! $this->isFinal() && $next->rank() > $this->rank();
    }

    /** @return list<self> */
    public static function inFlight(): array
    {
        return [self::Pending, self::Queued, self::Processing];
    }
}
