<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Archive, Lock, ShieldCheck } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{
    schedules: {
        id: string;
        code: string;
        name: string;
        trigger_event: string;
        retention_days: number;
        disposition_action: string;
    }[];
    governedDocuments: {
        id: string;
        doc_number: string;
        title: string;
        classification_rank: number;
        classification_name: string | null;
        retention_schedule_code: string | null;
        retention_expires_at: string | null;
        legal_hold_active: boolean;
        is_worm: boolean;
    }[];
    dispositionReviews: {
        id: string;
        doc_number: string | null;
        document_title: string | null;
        schedule_code: string | null;
        proposed_action: string;
        status: string;
        initiator_name: string | null;
        notes: string | null;
    }[];
    managedUsers: {
        id: string;
        name: string;
        email: string;
        rank_title: string | null;
        clearance_rank: number;
        clearance_name: string | null;
        compartments: string[];
    }[];
    clearanceLevels: { id: string; rank: number; code: string; name: string }[];
    canManageGovernance: boolean;
    isSystemAdmin: boolean;
}>();

const scheduleForm = useForm({
    code: 'RET-CUSTOM-10Y',
    name: 'Statutory 10-Year Archive',
    trigger_event: 'created_at',
    retention_days: 3650,
    disposition_action: 'archive',
    description: 'ISO 15489 compliance schedule',
});

const userClearanceSelection = ref<Record<string, string>>({});
const userClearanceReason = ref<Record<string, string>>({});

function createSchedule(): void {
    scheduleForm.post('/governance/schedules', { preserveScroll: true });
}

function submitClearanceChange(userId: string): void {
    const levelId = userClearanceSelection.value[userId];
    if (!levelId) {
        return;
    }
    router.post(
        `/admin/users/${userId}/clearance`,
        {
            clearance_level_id: levelId,
            reason: userClearanceReason.value[userId] || 'Periodic clearance review / operational update.',
        },
        { preserveScroll: true }
    );
}
</script>

<template>
    <Head title="Records Governance & WORM" />
    <AppLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-xl font-bold text-slate-900">Records Governance, Retention, Legal Hold &amp; WORM Vault</h1>
                <p class="text-xs text-slate-600">ISO 15489 retention enforcement, immutable WORM Object Lock sealing, and Two-Person Disposition reviews.</p>
            </div>

            <!-- Retention Schedules -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div v-if="canManageGovernance" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs text-xs">
                    <h2 class="mb-3 font-bold uppercase tracking-wider text-slate-700">New Retention Schedule</h2>
                    <form @submit.prevent="createSchedule" class="space-y-3">
                        <input v-model="scheduleForm.code" type="text" required placeholder="Schedule Code" class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono" />
                        <input v-model="scheduleForm.name" type="text" required placeholder="Schedule Name" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                        <div class="grid grid-cols-2 gap-2">
                            <input v-model.number="scheduleForm.retention_days" type="number" min="1" required class="rounded-lg border border-slate-300 px-3 py-2" />
                            <select v-model="scheduleForm.disposition_action" class="rounded-lg border border-slate-300 px-2 py-2">
                                <option value="archive">Archive</option>
                                <option value="review_transfer">Review / Transfer</option>
                                <option value="destroy">Destroy</option>
                            </select>
                        </div>
                        <button type="submit" :disabled="scheduleForm.processing" class="w-full rounded-lg bg-slate-900 px-3 py-2 font-semibold text-white">
                            Save Retention Rule
                        </button>
                    </form>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-2">
                    <h2 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-700">Active Retention Policies</h2>
                    <div class="divide-y divide-slate-100 text-xs">
                        <div v-for="s in schedules" :key="s.id" class="flex items-center justify-between py-2.5">
                            <div>
                                <div class="font-semibold text-slate-900">{{ s.code }} — {{ s.name }}</div>
                                <div class="text-slate-500">Trigger: {{ s.trigger_event }} · Period: {{ s.retention_days }} days · Action: {{ s.disposition_action }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cleared Records Ledger -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <h2 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-700">Records Governance Status (Legal Hold &amp; WORM)</h2>
                <div class="divide-y divide-slate-100 text-xs">
                    <div v-for="doc in governedDocuments" :key="doc.id" class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                        <div>
                            <Link :href="`/documents/${doc.id}`" class="font-mono font-bold text-sky-700 hover:underline">{{ doc.doc_number }}</Link>
                            <span class="ml-2 font-medium text-slate-900">{{ doc.title }}</span>
                            <div class="text-[11px] text-slate-500">
                                Retention: {{ doc.retention_schedule_code ?? 'Unassigned' }}
                                <span v-if="doc.retention_expires_at"> (Expires: {{ doc.retention_expires_at.slice(0, 10) }})</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span v-if="doc.legal_hold_active" class="inline-flex items-center gap-1 rounded bg-amber-100 px-2 py-0.5 font-semibold text-amber-900">
                                <ShieldCheck class="h-3 w-3" /> LEGAL HOLD
                            </span>
                            <span v-if="doc.is_worm" class="inline-flex items-center gap-1 rounded bg-slate-900 px-2 py-0.5 font-semibold text-white">
                                <Lock class="h-3 w-3" /> WORM SEALED
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Disposition Reviews -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <h2 class="mb-3 flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-slate-700">
                    <Archive class="h-4 w-4 text-slate-600" />
                    <span>Disposition Reviews (Two-Person Rule Enforced)</span>
                </h2>
                <div v-if="dispositionReviews.length === 0" class="text-xs text-slate-500">No pending or completed disposition reviews.</div>
                <div v-else class="divide-y divide-slate-100 text-xs">
                    <div v-for="rev in dispositionReviews" :key="rev.id" class="flex items-center justify-between py-2">
                        <div>
                            <span class="font-mono font-bold">{{ rev.doc_number }}</span> — {{ rev.document_title }}
                            <div class="text-slate-500">Action: {{ rev.proposed_action }} · Schedule: {{ rev.schedule_code }} · Initiated by {{ rev.initiator_name }}</div>
                        </div>
                        <span class="rounded bg-slate-100 px-2 py-0.5 font-mono text-[10px] font-bold uppercase">{{ rev.status }}</span>
                    </div>
                </div>
            </div>

            <!-- Admin Clearance Management & Synchronous Revocation -->
            <div v-if="isSystemAdmin && managedUsers.length > 0" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <h2 class="mb-1 text-xs font-bold uppercase tracking-wider text-slate-700">Personnel Clearance Governance (Sync WOPI Revocation + 2-Person Elevation)</h2>
                <p class="mb-3 text-[11px] text-slate-500">Downgrading clearance revokes active Collabora/WOPI editing sessions immediately; elevating clearance queues a Two-Person approval request.</p>
                <div class="divide-y divide-slate-100 text-xs">
                    <div v-for="u in managedUsers" :key="u.id" class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                        <div>
                            <div class="font-semibold text-slate-900">{{ u.rank_title ? `${u.rank_title} ` : '' }}{{ u.name }}</div>
                            <div class="font-mono text-[11px] text-slate-500">{{ u.email }} · Current: L{{ u.clearance_rank }} {{ u.clearance_name }}</div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <select v-model="userClearanceSelection[u.id]" class="rounded border border-slate-300 px-2 py-1 text-xs">
                                <option value="">Select target level...</option>
                                <option v-for="lvl in clearanceLevels" :key="lvl.id" :value="lvl.id">L{{ lvl.rank }} — {{ lvl.name }}</option>
                            </select>
                            <input v-model="userClearanceReason[u.id]" type="text" placeholder="Justification (min 10 chars)" class="w-44 rounded border border-slate-300 px-2 py-1 text-xs" />
                            <button type="button" @click="submitClearanceChange(u.id)" class="rounded bg-slate-900 px-3 py-1 font-semibold text-white hover:bg-slate-800">
                                Apply Change
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
