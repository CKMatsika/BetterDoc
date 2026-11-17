<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { GitFork, Play, Save } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import WorkflowDesigner, { type BpmnEdge, type BpmnNode } from '@/Components/WorkflowDesigner.vue';

const props = defineProps<{
    definitions: {
        id: string;
        key: string;
        name: string;
        description: string | null;
        version: number;
        sla_hours: number;
        bpmn_graph_json: { nodes: BpmnNode[]; edges: BpmnEdge[] };
    }[];
    instances: {
        id: string;
        definition_name: string | null;
        document_id: string;
        doc_number: string | null;
        document_title: string | null;
        starter_name: string | null;
        status: string;
        sla_due_at: string | null;
        tasks: {
            id: string;
            node_label: string;
            assignee_name: string | null;
            status: string;
            decision: string | null;
            approval_hash: string | null;
        }[];
    }[];
    documents: { id: string; doc_number: string; title: string; classification_rank: number }[];
    users: { id: string; name: string; rank_title: string | null; clearance_rank: number }[];
    roles: string[];
}>();

const showDesigner = ref(true);

const graphData = ref<{ nodes: BpmnNode[]; edges: BpmnEdge[] }>({
    nodes: [
        { id: 'n_start', type: 'start', label: 'Start Request', x: 30, y: 130 },
        { id: 'n_rev', type: 'approval', label: 'Records Verification', assignee_role: 'records_officer', sla_hours: 24, x: 210, y: 130 },
        { id: 'n_app', type: 'approval', label: 'Command Approval', assignee_role: 'approver', sla_hours: 12, x: 400, y: 130 },
        { id: 'n_end', type: 'end', label: 'Completed', x: 580, y: 130 },
    ],
    edges: [
        { id: 'e1', source: 'n_start', target: 'n_rev', condition: '', is_default: false },
        { id: 'e2', source: 'n_rev', target: 'n_app', condition: '', is_default: false },
        { id: 'e3', source: 'n_app', target: 'n_end', condition: '', is_default: false },
    ],
});

const defForm = useForm({
    key: 'custom-clearance-flow',
    name: 'Standard Dual-Review Routing',
    description: 'Visual drag-and-drop approval workflow with SLA escalation.',
    sla_hours: 48,
    bpmn_graph_json: graphData.value,
    form_schema_json: [{ key: 'minute_note', label: 'Decision Remark', required: true }],
});

const startForm = useForm({
    definition_id: props.definitions[0]?.id ?? '',
    document_id: props.documents[0]?.id ?? '',
});

function saveDefinition(): void {
    defForm.bpmn_graph_json = graphData.value;
    defForm.post('/workflows/definitions', { preserveScroll: true });
}

function launchWorkflow(): void {
    if (!startForm.document_id || !startForm.definition_id) {
        return;
    }
    startForm.post(`/documents/${startForm.document_id}/workflows/start`, { preserveScroll: true });
}
</script>

<template>
    <Head title="Workflow Designer & Routing" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Visual BPMN Workflow Designer &amp; Instances</h1>
                    <p class="text-xs text-slate-600">Design approvals, parallel split/joins, conditional gateways, SLAs, and Tier 3 cryptographic sign-offs offline.</p>
                </div>
                <Link href="/workflows/inbox" class="rounded-lg bg-sky-600 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-500">
                    Open My Task Inbox
                </Link>
            </div>

            <!-- Visual BPMN Designer -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <div class="mb-4 flex items-center justify-between">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <GitFork class="h-4 w-4 text-sky-600" />
                        <span>Drag-and-Drop BPMN-Lite Canvas</span>
                    </div>
                    <button type="button" @click="showDesigner = !showDesigner" class="text-xs font-medium text-sky-700 hover:underline">
                        {{ showDesigner ? 'Hide Designer' : 'Show Designer' }}
                    </button>
                </div>

                <div v-if="showDesigner" class="space-y-4">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 text-xs">
                        <input v-model="defForm.key" type="text" placeholder="workflow-key" class="rounded-lg border border-slate-300 px-3 py-2 font-mono" />
                        <input v-model="defForm.name" type="text" placeholder="Workflow Title" class="rounded-lg border border-slate-300 px-3 py-2" />
                        <div class="flex items-center gap-2">
                            <input v-model.number="defForm.sla_hours" type="number" min="1" class="w-24 rounded-lg border border-slate-300 px-3 py-2" />
                            <span class="text-slate-600">Overall SLA (hrs)</span>
                        </div>
                    </div>

                    <WorkflowDesigner v-model="graphData" :roles="roles" :users="users" />

                    <div class="flex justify-end">
                        <button type="button" @click="saveDefinition" :disabled="defForm.processing" class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                            <Save class="h-3.5 w-3.5" /> Publish Workflow Version
                        </button>
                    </div>
                </div>
            </div>

            <!-- Start Workflow Instance on Cleared Document -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <h2 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-700">Launch Workflow on Document</h2>
                <form @submit.prevent="launchWorkflow" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <select v-model="startForm.definition_id" class="rounded-lg border border-slate-300 px-3 py-2 text-xs">
                        <option v-for="d in definitions" :key="d.id" :value="d.id">{{ d.name }} (v{{ d.version }})</option>
                    </select>
                    <select v-model="startForm.document_id" class="rounded-lg border border-slate-300 px-3 py-2 text-xs">
                        <option v-for="doc in documents" :key="doc.id" :value="doc.id">{{ doc.doc_number }} — {{ doc.title }}</option>
                    </select>
                    <button type="submit" :disabled="startForm.processing || !startForm.document_id" class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-500 disabled:opacity-50">
                        <Play class="h-3.5 w-3.5" /> Start Workflow Instance
                    </button>
                </form>
            </div>

            <!-- Active & Completed Instances -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <h2 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-700">Clearance-Filtered Workflow Instances</h2>
                <div v-if="instances.length === 0" class="text-xs text-slate-500">No workflow instances visible at your clearance level.</div>
                <div v-else class="divide-y divide-slate-100 text-xs">
                    <div v-for="inst in instances" :key="inst.id" class="py-3 space-y-1.5">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="font-semibold text-slate-900">{{ inst.definition_name }} · {{ inst.doc_number }}</span>
                            <span class="rounded px-2 py-0.5 font-mono text-[10px] font-bold uppercase" :class="inst.status === 'completed' ? 'bg-emerald-100 text-emerald-800' : inst.status === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-sky-100 text-sky-900'">
                                {{ inst.status }}
                            </span>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <span v-for="t in inst.tasks" :key="t.id" class="rounded border border-slate-200 bg-slate-50 px-2 py-0.5 font-mono text-[10px]">
                                {{ t.node_label }} ({{ t.assignee_name }}) -> {{ t.status }}
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
