<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, Check, Clock, Forward, X } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{
    tasks: {
        id: string;
        node_label: string;
        node_type: string;
        status: string;
        decision: string | null;
        decision_comment: string | null;
        approval_hash: string | null;
        due_at: string | null;
        is_actionable: boolean;
        workflow_name: string | null;
        form_schema: { key: string; label: string; required?: boolean }[];
        document: {
            id: string;
            doc_number: string;
            title: string;
            classification_rank: number;
        } | null;
        assignee_name: string | null;
        delegated_to_name: string | null;
    }[];
    delegateUsers: { id: string; name: string; rank_title: string | null; clearance_rank: number }[];
}>();

const commentByTask = ref<Record<string, string>>({});
const delegateByTask = ref<Record<string, string>>({});

function decide(taskId: string, decision: 'approve' | 'reject'): void {
    router.post(
        `/workflows/tasks/${taskId}/decide`,
        {
            decision,
            comment: commentByTask.value[taskId] ?? '',
            form_data: commentByTask.value[taskId] ? { minute_note: commentByTask.value[taskId] } : {},
        },
        { preserveScroll: true }
    );
}

function delegateTask(taskId: string): void {
    const delegateId = delegateByTask.value[taskId];
    if (!delegateId) {
        return;
    }
    router.post(
        `/workflows/tasks/${taskId}/delegate`,
        { delegate_user_id: delegateId, reason: 'Operational delegation via inbox.' },
        { preserveScroll: true }
    );
}

function triggerEscalationSweep(): void {
    router.post('/workflows/escalate-slas', {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Workflow Task Inbox" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Workflow Action Inbox &amp; SLA Queue</h1>
                    <p class="text-xs text-slate-600">Every approval emits a Tier 3 cryptographic SHA-256 attestation hash in the audit chain.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" @click="triggerEscalationSweep" class="inline-flex items-center gap-1.5 rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100">
                        <Clock class="h-3.5 w-3.5" /> Check SLA Escalations
                    </button>
                    <Link href="/workflows" class="rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white">
                        Workflow Designer
                    </Link>
                </div>
            </div>

            <div v-if="tasks.length === 0" class="rounded-xl border border-slate-200 bg-white p-8 text-center text-sm text-slate-500">
                No workflow tasks assigned or delegated to you.
            </div>

            <div v-else class="space-y-4">
                <div v-for="task in tasks" :key="task.id" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs text-xs">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="rounded bg-slate-900 px-2 py-0.5 font-mono text-[10px] font-bold text-white uppercase">{{ task.status }}</span>
                                <span v-if="task.status === 'escalated'" class="inline-flex items-center gap-1 rounded bg-red-100 px-2 py-0.5 font-semibold text-red-800">
                                    <AlertTriangle class="h-3 w-3" /> SLA Escalated
                                </span>
                                <span class="font-semibold text-slate-900">{{ task.node_label }}</span>
                            </div>
                            <p class="text-slate-700">
                                Workflow: <span class="font-medium">{{ task.workflow_name }}</span> ·
                                <Link v-if="task.document" :href="`/documents/${task.document.id}`" class="font-mono font-bold text-sky-700 hover:underline">
                                    {{ task.document.doc_number }} — {{ task.document.title }}
                                </Link>
                            </p>
                            <p v-if="task.approval_hash" class="font-mono text-[10px] text-emerald-700">
                                Tier 3 Attestation SHA-256: {{ task.approval_hash }}
                            </p>
                        </div>

                        <div v-if="task.is_actionable" class="w-full space-y-2 sm:w-auto sm:min-w-[320px]">
                            <input
                                v-model="commentByTask[task.id]"
                                type="text"
                                placeholder="Decision comment / form remark..."
                                class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs"
                            />
                            <div class="flex flex-wrap gap-2">
                                <button type="button" @click="decide(task.id, 'approve')" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 font-semibold text-white hover:bg-emerald-500">
                                    <Check class="h-3.5 w-3.5" /> Approve &amp; Attest
                                </button>
                                <button type="button" @click="decide(task.id, 'reject')" class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 font-semibold text-red-700 hover:bg-red-100">
                                    <X class="h-3.5 w-3.5" /> Reject
                                </button>
                            </div>
                            <div class="flex items-center gap-1.5 pt-1">
                                <select v-model="delegateByTask[task.id]" class="flex-1 rounded border border-slate-300 px-2 py-1 text-[11px]">
                                    <option value="">Delegate to colleague (MAC checked)...</option>
                                    <option v-for="u in delegateUsers" :key="u.id" :value="u.id">
                                        {{ u.rank_title ? `${u.rank_title} ` : '' }}{{ u.name }} (L{{ u.clearance_rank }})
                                    </option>
                                </select>
                                <button type="button" @click="delegateTask(task.id)" class="inline-flex items-center gap-1 rounded border border-slate-300 px-2 py-1 text-[11px] font-medium hover:bg-slate-50">
                                    <Forward class="h-3 w-3" /> Delegate
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
