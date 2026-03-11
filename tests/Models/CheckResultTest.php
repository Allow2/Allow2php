<?php

declare(strict_types=1);

namespace Allow2\Tests\Models;

use Allow2\Models\Activity;
use Allow2\Models\CheckResult;
use Allow2\Models\DayType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CheckResultTest extends TestCase
{
    #[Test]
    public function constructorSetsAllProperties(): void
    {
        $activities = [
            new Activity(id: 1, name: 'Internet', allowed: true, remaining: 3600, banned: false, timeBlockAllowed: true),
        ];
        $today = new DayType(id: 1, name: 'School Day');
        $tomorrow = new DayType(id: 2, name: 'Weekend');

        $result = new CheckResult(
            allowed: true,
            activities: $activities,
            todayDayType: $today,
            tomorrowDayType: $tomorrow,
            raw: ['test' => 'data'],
        );

        $this->assertTrue($result->allowed);
        $this->assertCount(1, $result->activities);
        $this->assertSame('School Day', $result->todayDayType->name);
        $this->assertSame('Weekend', $result->tomorrowDayType->name);
        $this->assertSame(['test' => 'data'], $result->raw);
    }

    #[Test]
    public function getActivityReturnsMatchingActivity(): void
    {
        $activities = [
            new Activity(id: 1, name: 'Internet', allowed: true, remaining: 3600, banned: false, timeBlockAllowed: true),
            new Activity(id: 3, name: 'Gaming', allowed: false, remaining: 0, banned: true, timeBlockAllowed: false),
        ];

        $result = new CheckResult(allowed: false, activities: $activities, todayDayType: null, tomorrowDayType: null);

        $gaming = $result->getActivity(3);
        $this->assertNotNull($gaming);
        $this->assertSame('Gaming', $gaming->name);
        $this->assertTrue($gaming->banned);
    }

    #[Test]
    public function getActivityReturnsNullForMissing(): void
    {
        $result = new CheckResult(allowed: true, activities: [], todayDayType: null, tomorrowDayType: null);
        $this->assertNull($result->getActivity(999));
    }

    #[Test]
    public function isActivityAllowedReturnsTrueForAllowedActivity(): void
    {
        $activities = [
            new Activity(id: 1, name: 'Internet', allowed: true, remaining: 3600, banned: false, timeBlockAllowed: true),
        ];
        $result = new CheckResult(allowed: true, activities: $activities, todayDayType: null, tomorrowDayType: null);

        $this->assertTrue($result->isActivityAllowed(1));
    }

    #[Test]
    public function isActivityAllowedReturnsFalseForBlockedActivity(): void
    {
        $activities = [
            new Activity(id: 3, name: 'Gaming', allowed: false, remaining: 0, banned: true, timeBlockAllowed: false),
        ];
        $result = new CheckResult(allowed: false, activities: $activities, todayDayType: null, tomorrowDayType: null);

        $this->assertFalse($result->isActivityAllowed(3));
    }

    #[Test]
    public function isActivityAllowedReturnsFalseForMissingActivity(): void
    {
        $result = new CheckResult(allowed: true, activities: [], todayDayType: null, tomorrowDayType: null);
        $this->assertFalse($result->isActivityAllowed(999));
    }

    #[Test]
    public function getRemainingSecondsReturnsValueForExistingActivity(): void
    {
        $activities = [
            new Activity(id: 1, name: 'Internet', allowed: true, remaining: 1800, banned: false, timeBlockAllowed: true),
        ];
        $result = new CheckResult(allowed: true, activities: $activities, todayDayType: null, tomorrowDayType: null);

        $this->assertSame(1800, $result->getRemainingSeconds(1));
    }

    #[Test]
    public function getRemainingSecondsReturnsZeroForMissingActivity(): void
    {
        $result = new CheckResult(allowed: true, activities: [], todayDayType: null, tomorrowDayType: null);
        $this->assertSame(0, $result->getRemainingSeconds(999));
    }

    #[Test]
    public function fromApiResponseParsesAllowedResponse(): void
    {
        $apiResponse = [
            'allowed' => true,
            'activities' => [
                [
                    'id' => 1,
                    'activity' => 'Internet',
                    'allowed' => true,
                    'remaining' => 3600,
                    'banned' => false,
                    'timeblock' => true,
                ],
                [
                    'id' => 3,
                    'activity' => 'Gaming',
                    'allowed' => true,
                    'remaining' => 1800,
                    'banned' => false,
                    'timeblock' => true,
                ],
            ],
            'dayType' => ['id' => 1, 'name' => 'School Day'],
            'tomorrowDayType' => ['id' => 2, 'name' => 'Weekend'],
        ];

        $result = CheckResult::fromApiResponse($apiResponse);

        $this->assertTrue($result->allowed);
        $this->assertCount(2, $result->activities);
        $this->assertSame('Internet', $result->activities[0]->name);
        $this->assertSame(3600, $result->activities[0]->remaining);
        $this->assertNotNull($result->todayDayType);
        $this->assertSame('School Day', $result->todayDayType->name);
        $this->assertNotNull($result->tomorrowDayType);
        $this->assertSame('Weekend', $result->tomorrowDayType->name);
    }

    #[Test]
    public function fromApiResponseParsesBlockedResponse(): void
    {
        $apiResponse = [
            'allowed' => false,
            'activities' => [
                [
                    'id' => 3,
                    'activity' => 'Gaming',
                    'allowed' => false,
                    'remaining' => 0,
                    'banned' => true,
                    'timeblock' => false,
                ],
            ],
            'dayType' => ['id' => 1, 'name' => 'School Day'],
        ];

        $result = CheckResult::fromApiResponse($apiResponse);

        $this->assertFalse($result->allowed);
        $this->assertCount(1, $result->activities);
        $this->assertTrue($result->activities[0]->banned);
        $this->assertFalse($result->activities[0]->allowed);
    }

    #[Test]
    public function fromApiResponseUsesAlternativeDayTypeKeys(): void
    {
        $apiResponse = [
            'activities' => [],
            'today' => ['id' => 5, 'name' => 'Holiday'],
            'tomorrow' => ['id' => 1, 'name' => 'School Day'],
        ];

        $result = CheckResult::fromApiResponse($apiResponse);

        $this->assertNotNull($result->todayDayType);
        $this->assertSame('Holiday', $result->todayDayType->name);
        $this->assertNotNull($result->tomorrowDayType);
        $this->assertSame('School Day', $result->tomorrowDayType->name);
    }

    #[Test]
    public function fromApiResponseHandlesMissingDayTypes(): void
    {
        $apiResponse = [
            'activities' => [],
        ];

        $result = CheckResult::fromApiResponse($apiResponse);

        $this->assertNull($result->todayDayType);
        $this->assertNull($result->tomorrowDayType);
    }

    #[Test]
    public function fromApiResponseInfersAllowedFromActivitiesWhenNotExplicit(): void
    {
        // No explicit 'allowed' key - should infer from activities
        $apiResponse = [
            'activities' => [
                ['id' => 1, 'activity' => 'Internet', 'allowed' => true, 'remaining' => 3600],
                ['id' => 3, 'activity' => 'Gaming', 'allowed' => false, 'remaining' => 0],
            ],
        ];

        $result = CheckResult::fromApiResponse($apiResponse);

        // One activity is not allowed, so global should be false
        $this->assertFalse($result->allowed);
    }

    #[Test]
    public function fromApiResponseExplicitAllowedOverridesInference(): void
    {
        // Server explicitly says allowed=true even though one activity is blocked
        $apiResponse = [
            'allowed' => true,
            'activities' => [
                ['id' => 1, 'activity' => 'Internet', 'allowed' => true, 'remaining' => 3600],
                ['id' => 3, 'activity' => 'Gaming', 'allowed' => false, 'remaining' => 0],
            ],
        ];

        $result = CheckResult::fromApiResponse($apiResponse);

        $this->assertTrue($result->allowed);
    }

    #[Test]
    public function fromApiResponsePreservesRawData(): void
    {
        $apiResponse = [
            'allowed' => true,
            'activities' => [],
            'extra_field' => 'extra_value',
        ];

        $result = CheckResult::fromApiResponse($apiResponse);

        $this->assertSame('extra_value', $result->raw['extra_field']);
    }
}
