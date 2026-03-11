<?php

declare(strict_types=1);

namespace Allow2\Models;

/**
 * Result of a permission check from the Allow2 service API.
 *
 * Contains the overall allowed status, per-activity breakdown,
 * and current/upcoming day type information.
 */
final class CheckResult
{
    /**
     * @param bool $allowed Whether the child is globally allowed right now.
     * @param Activity[] $activities Per-activity permission breakdown.
     * @param DayType|null $todayDayType The current day type.
     * @param DayType|null $tomorrowDayType The upcoming day type (if provided).
     * @param array $raw The raw API response for advanced use.
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly array $activities,
        public readonly ?DayType $todayDayType,
        public readonly ?DayType $tomorrowDayType,
        public readonly array $raw = [],
    ) {}

    /**
     * Get a specific activity by ID, or null if not present.
     */
    public function getActivity(int $activityId): ?Activity
    {
        foreach ($this->activities as $activity) {
            if ($activity->id === $activityId) {
                return $activity;
            }
        }
        return null;
    }

    /**
     * Check whether a specific activity is allowed.
     */
    public function isActivityAllowed(int $activityId): bool
    {
        $activity = $this->getActivity($activityId);
        return $activity !== null && $activity->allowed;
    }

    /**
     * Get remaining seconds for a specific activity.
     */
    public function getRemainingSeconds(int $activityId): int
    {
        $activity = $this->getActivity($activityId);
        return $activity !== null ? $activity->remaining : 0;
    }

    /**
     * Build from the raw API response.
     *
     * @param array $response The decoded JSON response from /serviceapi/check.
     */
    public static function fromApiResponse(array $response): self
    {
        $activities = [];
        $rawActivities = $response['activities'] ?? [];

        foreach ($rawActivities as $actData) {
            $activities[] = Activity::fromArray($actData);
        }

        $todayDayType = null;
        if (isset($response['dayType'])) {
            $todayDayType = DayType::fromArray($response['dayType']);
        } elseif (isset($response['today'])) {
            $todayDayType = DayType::fromArray($response['today']);
        }

        $tomorrowDayType = null;
        if (isset($response['tomorrowDayType'])) {
            $tomorrowDayType = DayType::fromArray($response['tomorrowDayType']);
        } elseif (isset($response['tomorrow'])) {
            $tomorrowDayType = DayType::fromArray($response['tomorrow']);
        }

        // Global "allowed" is true only if ALL activities are allowed
        $allowed = true;
        foreach ($activities as $act) {
            if (!$act->allowed) {
                $allowed = false;
                break;
            }
        }

        // Override with explicit server value if present
        if (isset($response['allowed'])) {
            $allowed = (bool) $response['allowed'];
        }

        return new self(
            allowed: $allowed,
            activities: $activities,
            todayDayType: $todayDayType,
            tomorrowDayType: $tomorrowDayType,
            raw: $response,
        );
    }
}
