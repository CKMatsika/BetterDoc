<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Check, KeyRound, X } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { SharedProps } from '@/types/betterdoc';

interface TwoPersonItem {
    id: string;
    action_type: string;
    target_type: string;
    target_id: string;
    target_classification_rank: number;
    reason: string;
    status: string;
    requester_name: string | null;
    approver_name: string | null;
    can_approve: boolean;
    created_at: string;
}

interface BreakGlassItem {
    id: string;
    user_name: string | null;
    document_id: string;
    justification: string;
    status: string;
    first_approver_name: string | null;
    second_approver_name: string | null;
    expires_at: string | null;
}

defineProps<{
    twoPersonRequests: TwoPersonItem[];
    breakGlassSessions: BreakGlassItem[];
}>();

const page = usePage<{ props: SharedProps }>();
const shared = computed(() => page.props as unknown as SharedProps);

const stepUpForm = useForm({ code: '' });
const bgForm = useForm({
    document_id: '',
    justification: '',
    duration_hours: 2,
});

function verifyStepUp(): void {
    stepUpForm.post('/security/mfa/step-up', { onSuccess: () => stepUpForm.reset() });
}

function approveTp(id: string): void {
    router.post(`/approvals/${id}/approve`);
}

function rejectTp(id: string): void {
    router.post(`/approvals/${id}/reject`, { note: 'Denied by reviewing officer.' });
}

function submitBreakGlass(): void {
    bgForm.post('/break-glass', { onSuccess: () => bgForm.reset() });
}
</script>

<template>
    <Head title="Approvals & Break-Glass" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Two-Person Rule &amp; Emergency Break-Glass</h1>
                    <p class="text-xs text-slate-600">Dual control prevents unilateral declassification, deletion, or emergency override.</p>
                </div>

                <!-- Inline MFA Step-Up Status -->
                <div class="flex items-center gap-2 rounded-lg border px-3 py-2 text-xs" :class="shared.auth?.user?.mfa_step_up_fresh ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : 'border-amber-300 bg-amber-50 text-amber-950'">
                    <KeyRound class="h-4 w-4 shrink-0" />
                    <span v-if="shared.auth?.user?.mfa_step_up_fresh" class="font-semibold">Step-Up MFA Fresh (Ready to Co-Sign)</span>
                    <form v-else @submit.prevent="verifyStepUp" class="flex items-center gap-2">
                        <input v-model="stepUpForm.code" type="text" required placeholder="TOTP / Recovery" class="w-28 rounded border border-amber-400 bg-white px-2 py-1 font-mono text-xs" />
                        <button type="submit" class="rounded bg-amber-600 px-2.5 py-1 font-semibold text-white">Step-Up</button>
                    </form>
                </div>
            </div>

            <!-- Two-Person Queue -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <h2 class="mb-3 text-sm font-bold uppercase tracking-wider text-slate-700">Two-Person Approval Queue</h2>
                <div v-if="twoPersonRequests.length === 0" class="text-sm text-slate-500">No two-person requests recorded.</div>
                <div v-else class="divide-y divide-slate-100">
                    <div v-for="req in twoPersonRequests" :key="req.id" class="flex flex-wrap items-center justify-between gap-4 py-3">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <span class="rounded bg-slate-900 px-2 py-0.5 font-mono text-[11px] font-semibold text-white uppercase">{{ req.action_type }}</span>
                                <span class="rounded px-2 py-0.5 text-[11px] font-semibold uppercase" :class="req.status === 'approved' ? 'bg-emerald-100 text-emerald-800' : req.status === 'rejected' ? 'bg-red-100 text-red-800' : 'bg-amber-100 text-amber-900'">
                                    {{ req.status }}
                                </span>
                                <span class="font-mono text-xs text-slate-500">Target Rank L{{ req.target_classification_rank }}</span>
                            </div>
                            <p class="text-sm font-medium text-slate-900">{{ req.reason }}</p>
                            <p class="text-xs text-slate-500">
                                Requested by {{ req.requester_name }}
                                <span v-if="req.approver_name"> · Decided by {{ req.approver_name }}</span>
                            </p>
                        </div>

                        <div v-if="req.can_approve" class="flex items-center gap-2">
                            <button type="button" @click="approveTp(req.id)" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500">
                                <Check class="h-3.5 w-3.5" /> Co-Approve
                            </button>
                            <button type="button" @click="rejectTp(req.id)" class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">
                                <X class="h-3.5 w-3.5" /> Reject
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Emergency Break-Glass -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="rounded-xl border border-red-200 bg-white p-5 shadow-xs">
                    <div class="mb-3 flex items-center gap-2 text-sm font-bold text-red-700">
                        <AlertTriangle class="h-4 w-4" />
                        <span>Request Emergency Break-Glass</span>
                    </div>
                    <form @submit.prevent="submitBreakGlass" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">Target Document UUID</label>
                            <input v-model="bgForm.document_id" type="text" required placeholder="0193..." class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">Operational Justification (min 30 chars)</label>
                            <textarea v-model="bgForm.justification" rows="3" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs" />
                            <p v-if="bgForm.errors.justification" class="mt-1 text-xs text-red-600">{{ bgForm.errors.justification }}</p>
                        </div>
                        <button type="submit" class="w-full rounded-lg bg-red-700 px-4 py-2 text-xs font-semibold text-white hover:bg-red-600">
                            Submit Break-Glass Request
                        </button>
                    </form>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-2">
                    <h2 class="mb-3 text-sm font-bold uppercase tracking-wider text-slate-700">Break-Glass Audit Queue (Dual Approval)</h2>
                    <div v-if="breakGlassSessions.length === 0" class="text-sm text-slate-500">No emergency break-glass sessions logged.</div>
                    <div v-else class="space-y-3">
                        <div v-for="bg in breakGlassSessions" :key="bg.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-3 text-xs">
                            <div>
                                <div class="font-semibold text-slate-900">{{ bg.user_name }} -> Doc {{ bg.document_id.slice(0, 8) }}...</div>
                                <p class="text-slate-600">{{ bg.justification }}</p>
                                <p class="font-mono text-[11px] text-slate-500">Status: {{ bg.status }}</p>
                            </div>
                            <div class="flex gap-2">
                                <button v-if="bg.status === 'pending_first'" type="button" @click="router.post(`/break-glass/${bg.id}/approve-first`)" class="rounded bg-amber-600 px-3 py-1.5 font-semibold text-white">
                                    1st Approval
                                </button>
                                <button v-if="bg.status === 'pending_second'" type="button" @click="router.post(`/break-glass/${bg.id}/approve-second`)" class="rounded bg-red-700 px-3 py-1.5 font-semibold text-white">
                                    2nd Approval (Activate)
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
