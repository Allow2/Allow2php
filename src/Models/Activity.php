<?php

declare(strict_types=1);

namespace Allow2\Models;

/**
 * Represents the permission state of a single activity from a check result.
 */
final class Activity
{
    /** Screen Time activity ID — master device-level switch. */
    public const SCREEN_TIME = 8;

    /** Gaming activity ID. */
    public const GAMING = 3;

    /** Internet activity ID. */
    public const INTERNET = 1;

    /** Social Media activity ID. */
    public const SOCIAL = 6;

    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly bool $allowed,
        public readonly int $remaining,
        public readonly bool $banned,
        public readonly bool $timeBlockAllowed,
    ) {}

    /**
     * Create from a single activity entry in the API check response.
     *
     * @param array{id: int, activity?: string, allowed: bool|int, remaining?: int, banned?: bool|int, timeblock?: bool|int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: (int) $data['id'],
            name: (string) ($data['activity'] ?? $data['name'] ?? ''),
            allowed: (bool) ($data['allowed'] ?? false),
            remaining: (int) ($data['remaining'] ?? 0),
            banned: (bool) ($data['banned'] ?? false),
            timeBlockAllowed: (bool) ($data['timeblock'] ?? $data['timeBlockAllowed'] ?? true),
        );
    }
}
