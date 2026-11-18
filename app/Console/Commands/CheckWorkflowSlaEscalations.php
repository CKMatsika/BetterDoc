<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Workflow\WorkflowEngineService;
use Illuminate\Console\Command;

class CheckWorkflowSlaEscalations extends Command
{
    protected $signature = 'betterdoc:workflow:check-slas {--tenant= : Optional tenant UUID to scope check}';

    protected $description = 'Check pending BPMN workflow tasks against SLA deadlines and escalate overdue steps';

    public function handle(WorkflowEngineService $engine): int
    {
        $tenantOpt = $this->option('tenant');
        $tenantId = is_string($tenantOpt) && $tenantOpt !== '' ? $tenantOpt : null;
        $escalated = $engine->checkAndEscalateOverdueTasks($tenantId);

        $this->info("SLA sweep finished: {$escalated} task(s) escalated.");

        return self::SUCCESS;
    }
}
