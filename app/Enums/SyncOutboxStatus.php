<?php

declare(strict_types=1);

namespace App\Enums;

enum SyncOutboxStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case BlockedAirgap = 'blocked_airgap';
    case BlockedClassification = 'blocked_classification';
    case ConflictBranched = 'conflict_branched';
    case Failed = 'failed';
}
