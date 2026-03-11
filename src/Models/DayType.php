<?php

declare(strict_types=1);

namespace Allow2\Models;

/**
 * Represents a day type in the Allow2 system (e.g., School Day, Weekend, Holiday).
 */
final class DayType
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
    ) {}

    /**
     * Create from API response data.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            name: (string) $data['name'],
        );
    }
}
