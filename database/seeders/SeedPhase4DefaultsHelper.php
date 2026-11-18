<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\BetterDocRole;
use App\Enums\DispositionAction;
use App\Enums\ProfileCode;
use App\Enums\RetentionTrigger;
use App\Enums\TemplateCategory;
use App\Models\ClearanceLevel;
use App\Models\DocumentTemplate;
use App\Models\RetentionSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkflowDefinition;

class SeedPhase4DefaultsHelper
{
    /**
     * Seed baseline BPMN-lite workflows, mail-merge templates, and retention schedules per tenant profile.
     */
    public static function seedDefaults(Tenant $tenant, User $creator): void
    {
        $profile = $tenant->active_profile_code;

        $level1 = ClearanceLevel::query()
            ->where('tenant_id', $tenant->id)
            ->where('rank', 1)
            ->first();

        // 1. Default Two-Stage / Conditional Visual Workflow
        $graph = [
            'nodes' => [
                ['id' => 'n_start', 'type' => 'start', 'label' => 'Start Submission', 'x' => 60, 'y' => 140],
                ['id' => 'n_gw', 'type' => 'conditional_gateway', 'label' => 'Sensitive Classification?', 'x' => 230, 'y' => 140],
                ['id' => 'n_records', 'type' => 'approval', 'label' => 'Records Officer Review', 'assignee_role' => BetterDocRole::RecordsOfficer->value, 'sla_hours' => 24, 'x' => 420, 'y' => 60],
                ['id' => 'n_exec', 'type' => 'approval', 'label' => 'Senior Approver Sign-Off', 'assignee_role' => BetterDocRole::Approver->value, 'sla_hours' => 12, 'x' => 420, 'y' => 220],
                ['id' => 'n_end', 'type' => 'end', 'label' => 'Workflow Completed', 'x' => 650, 'y' => 140],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'n_start', 'target' => 'n_gw', 'condition' => '', 'is_default' => false],
                ['id' => 'e2', 'source' => 'n_gw', 'target' => 'n_exec', 'condition' => 'classification_rank >= 2', 'is_default' => false],
                ['id' => 'e3', 'source' => 'n_gw', 'target' => 'n_records', 'condition' => '', 'is_default' => true],
                ['id' => 'e4', 'source' => 'n_records', 'target' => 'n_end', 'condition' => '', 'is_default' => false],
                ['id' => 'e5', 'source' => 'n_exec', 'target' => 'n_end', 'condition' => '', 'is_default' => false],
            ],
        ];

        WorkflowDefinition::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'key' => 'clearance-routed-approval', 'version' => 1],
            [
                'name' => match ($profile) {
                    ProfileCode::Defence => 'Command Directive Clearance Routing',
                    ProfileCode::Financial => 'Credit & Compliance Approval Route',
                    ProfileCode::Government => 'Directorate & Permanent Secretary Approval',
                    ProfileCode::Corporate => 'Executive Capex & Contract Approval',
                },
                'description' => 'Automatically routes rank >= 2 records to Senior Approver and lower ranks through Records Officer review.',
                'bpmn_graph_json' => $graph,
                'form_schema_json' => [
                    ['key' => 'review_notes', 'label' => 'Decision Justification / Minute', 'required' => true],
                ],
                'sla_hours' => 24,
                'is_active' => true,
                'created_by' => $creator->id,
            ]
        );

        // 2. Default Mail-Merge Templates
        $tplSpecs = match ($profile) {
            ProfileCode::Defence => [
                'code' => 'AFZ-MEMO-01',
                'name' => 'Command Signal Memorandum',
                'category' => TemplateCategory::Memo,
                'prefix' => 'AFZ/MEMO',
                'body' => '<p><strong>DATE:</strong> {{date}}</p><p><strong>TO:</strong> {{recipient_name}} ({{unit_code}})</p><p><strong>SUBJECT:</strong> {{subject}}</p><hr/><p>{{body_text}}</p><p>Signed for Air Force Command Registry.</p>',
            ],
            ProfileCode::Financial => [
                'code' => 'GK-MEMO-01',
                'name' => 'Credit & Compliance Committee Memo',
                'category' => TemplateCategory::Memo,
                'prefix' => 'GK/CREDIT',
                'body' => '<p><strong>DATE:</strong> {{date}}</p><p><strong>CLIENT / ENTITY:</strong> {{recipient_name}} ({{unit_code}})</p><p><strong>FACILITY SUBJECT:</strong> {{subject}}</p><hr/><p>{{body_text}}</p><p>Subject to Golden Knot Risk &amp; RBZ AML Governance.</p>',
            ],
            ProfileCode::Corporate => [
                'code' => 'ZC-MEMO-01',
                'name' => 'Corporate Board & Supplier Letter',
                'category' => TemplateCategory::Letter,
                'prefix' => 'ZC/CORP',
                'body' => '<p><strong>DATE:</strong> {{date}}</p><p><strong>ATTENTION:</strong> {{recipient_name}} ({{unit_code}})</p><p><strong>RE:</strong> {{subject}}</p><hr/><p>{{body_text}}</p><p>Issued via ZimCorp Enterprise Document Hub.</p>',
            ],
            ProfileCode::Government => [
                'code' => 'GOV-MIN-01',
                'name' => 'Ministerial Minute & Gazette Notice',
                'category' => TemplateCategory::Memo,
                'prefix' => 'GOV/MIN',
                'body' => '<p><strong>DATE:</strong> {{date}}</p><p><strong>RECIPIENT:</strong> {{recipient_name}} ({{unit_code}})</p><p><strong>SUBJECT:</strong> {{subject}}</p><hr/><p>{{body_text}}</p><p>Issued under Official Public Records Registry.</p>',
            ],
        };

        DocumentTemplate::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'code' => $tplSpecs['code']],
            [
                'name' => $tplSpecs['name'],
                'category' => $tplSpecs['category'],
                'body_html' => $tplSpecs['body'],
                'placeholders_json' => [
                    ['key' => 'recipient_name', 'label' => 'Recipient / Counterparty Name', 'required' => true],
                    ['key' => 'unit_code', 'label' => 'Unit / Department / Branch', 'required' => true],
                    ['key' => 'subject', 'label' => 'Document Subject', 'required' => true],
                    ['key' => 'body_text', 'label' => 'Official Body Content', 'required' => true],
                ],
                'default_classification_level_id' => $level1?->id,
                'auto_number_prefix' => $tplSpecs['prefix'],
                'next_sequence' => 1,
                'is_active' => true,
                'created_by' => $creator->id,
            ]
        );

        // 3. Default ISO 15489 Retention Schedules
        $schedules = match ($profile) {
            ProfileCode::Defence => [
                ['code' => 'DEF-OPS-25Y', 'name' => 'Operational Signals & Command Directives (25 Years)', 'days' => 9125, 'action' => DispositionAction::Archive],
                ['code' => 'DEF-ADMIN-3Y', 'name' => 'Routine Base Notices & Rosters (3 Years)', 'days' => 1095, 'action' => DispositionAction::Destroy],
            ],
            ProfileCode::Financial => [
                ['code' => 'FIN-AML-7Y', 'name' => 'RBZ / FIU AML & ZIMRA Tax Statutory (7 Years)', 'days' => 2555, 'action' => DispositionAction::Archive],
                ['code' => 'FIN-LOAN-5Y', 'name' => 'Settled Credit Dossiers (5 Years)', 'days' => 1825, 'action' => DispositionAction::ReviewTransfer],
            ],
            ProfileCode::Corporate => [
                ['code' => 'CORP-TAX-6Y', 'name' => 'Corporate Contracts & Tax Records (6 Years)', 'days' => 2190, 'action' => DispositionAction::Archive],
                ['code' => 'CORP-GEN-2Y', 'name' => 'General Commercial Memos (2 Years)', 'days' => 730, 'action' => DispositionAction::Destroy],
            ],
            ProfileCode::Government => [
                ['code' => 'GOV-ARCH-30Y', 'name' => 'Statutory Instruments & National Archives (30 Years)', 'days' => 10950, 'action' => DispositionAction::Archive],
                ['code' => 'GOV-CORR-7Y', 'name' => 'Departmental Correspondence (7 Years)', 'days' => 2555, 'action' => DispositionAction::ReviewTransfer],
            ],
        };

        foreach ($schedules as $s) {
            RetentionSchedule::query()->firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $s['code']],
                [
                    'name' => $s['name'],
                    'trigger_event' => RetentionTrigger::CreatedAt,
                    'retention_days' => $s['days'],
                    'disposition_action' => $s['action'],
                    'description' => 'ISO 15489 statutory retention schedule seeded by BetterDoc.',
                    'is_active' => true,
                ]
            );
        }
    }
}
