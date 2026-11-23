<?php

namespace App\Enums;

enum Quality: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public static function default(): self
    {
        return self::Medium;
    }
}
