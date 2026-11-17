<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkflowTaskStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Escalated = 'escalated';
    case Delegated = 'delegated';
    case Skipped = 'skipped';
}
