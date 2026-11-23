<?php

namespace App\Enums;

/**
 * Local lifecycle: pending → queued → processing → succeeded | failed.
 * `pending` exists only locally (not yet accepted by Pixelforge).
 * The forward-only transition rules live in GenerationSync (S5).
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }

    /** UI label; `pending` is shown as "Queued". */
    public function label(): string
    {
        return match ($this) {
            self::Pending, self::Queued => 'Queued',
            self::Processing => 'Processing',
            self::Succeeded => 'Succeeded',
            self::Failed => 'Failed',
        };
    }
}
