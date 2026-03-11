<?php

declare(strict_types=1);

namespace Allow2\Models;

/**
 * Categories for feedback submissions.
 */
enum FeedbackCategory: string
{
    case Bug = 'bug';
    case FeatureRequest = 'feature_request';
    case NotWorking = 'not_working';
    case Other = 'other';
}
