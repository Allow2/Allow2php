<?php

declare(strict_types=1);

namespace Allow2\Models;

/**
 * Types of requests a child can make.
 */
enum RequestType: string
{
    case MoreTime = 'extension';
    case DayTypeChange = 'daytype';
    case BanLift = 'banlift';

    /**
     * Numeric code used in voice challenge generation.
     * T field in the T A MM NN format.
     */
    public function voiceCode(): int
    {
        return match ($this) {
            self::MoreTime => 0,
            self::DayTypeChange => 1,
            self::BanLift => 2,
        };
    }
}
