<?php

declare(strict_types=1);

namespace App\Enums;

enum WorkflowInstanceStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Escalated = 'escalated';
}
