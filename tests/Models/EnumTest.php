<?php

declare(strict_types=1);

namespace Allow2\Tests\Models;

use Allow2\Models\FeedbackCategory;
use Allow2\Models\RequestType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EnumTest extends TestCase
{
    // --- RequestType ---

    #[Test]
    public function requestTypeMoreTimeHasCorrectValue(): void
    {
        $this->assertSame('extension', RequestType::MoreTime->value);
    }

    #[Test]
    public function requestTypeDayTypeChangeHasCorrectValue(): void
    {
        $this->assertSame('daytype', RequestType::DayTypeChange->value);
    }

    #[Test]
    public function requestTypeBanLiftHasCorrectValue(): void
    {
        $this->assertSame('banlift', RequestType::BanLift->value);
    }

    #[Test]
    public function requestTypeVoiceCodeMoreTimeIsZero(): void
    {
        $this->assertSame(0, RequestType::MoreTime->voiceCode());
    }

    #[Test]
    public function requestTypeVoiceCodeDayTypeChangeIsOne(): void
    {
        $this->assertSame(1, RequestType::DayTypeChange->voiceCode());
    }

    #[Test]
    public function requestTypeVoiceCodeBanLiftIsTwo(): void
    {
        $this->assertSame(2, RequestType::BanLift->voiceCode());
    }

    #[Test]
    public function requestTypeCanBeCreatedFromString(): void
    {
        $type = RequestType::from('extension');
        $this->assertSame(RequestType::MoreTime, $type);

        $type = RequestType::from('daytype');
        $this->assertSame(RequestType::DayTypeChange, $type);

        $type = RequestType::from('banlift');
        $this->assertSame(RequestType::BanLift, $type);
    }

    #[Test]
    public function requestTypeTryFromReturnsNullForInvalid(): void
    {
        $this->assertNull(RequestType::tryFrom('invalid'));
    }

    #[Test]
    public function requestTypeHasThreeCases(): void
    {
        $this->assertCount(3, RequestType::cases());
    }

    // --- FeedbackCategory ---

    #[Test]
    public function feedbackCategoryBugHasCorrectValue(): void
    {
        $this->assertSame('bug', FeedbackCategory::Bug->value);
    }

    #[Test]
    public function feedbackCategoryFeatureRequestHasCorrectValue(): void
    {
        $this->assertSame('feature_request', FeedbackCategory::FeatureRequest->value);
    }

    #[Test]
    public function feedbackCategoryNotWorkingHasCorrectValue(): void
    {
        $this->assertSame('not_working', FeedbackCategory::NotWorking->value);
    }

    #[Test]
    public function feedbackCategoryOtherHasCorrectValue(): void
    {
        $this->assertSame('other', FeedbackCategory::Other->value);
    }

    #[Test]
    public function feedbackCategoryCanBeCreatedFromString(): void
    {
        $this->assertSame(FeedbackCategory::Bug, FeedbackCategory::from('bug'));
        $this->assertSame(FeedbackCategory::Other, FeedbackCategory::from('other'));
    }

    #[Test]
    public function feedbackCategoryTryFromReturnsNullForInvalid(): void
    {
        $this->assertNull(FeedbackCategory::tryFrom('nonexistent'));
    }

    #[Test]
    public function feedbackCategoryHasFourCases(): void
    {
        $this->assertCount(4, FeedbackCategory::cases());
    }
}
