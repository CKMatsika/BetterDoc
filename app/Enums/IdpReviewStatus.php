<?php

declare(strict_types=1);

namespace App\Enums;

enum IdpReviewStatus: string
{
    case PendingReview = 'pending_review';
    case Approved = 'approved';
    case Corrected = 'corrected';
    case Rejected = 'rejected';
}
