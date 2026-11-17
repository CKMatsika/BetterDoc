<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import {
    CheckCircle2,
    FileDown,
    KeyRound,
    PenTool,
    RefreshCw,
    Send,
    ShieldAlert,
    ShieldCheck,
    XCircle,
} from '@lucide/vue';
import SignaturePadCanvas from '@/Components/SignaturePadCanvas.vue';
import type { SharedProps } from '@/types/sentinel';

export interface SignatureRequestItem {
    id: string;
    signer_id: string;
    signer_name: string;
    sign_order: number;
    tier: string;
    status: string;
    field_name: string;
    field_coords: { page: number; x: number; y: number; width: number; height: number };
    cert_serial: string | null;
    tsa_token_serial: string | null;
    signed_at: string | null;
    decline_reason: string | null;
    reminder_count: number;
    is_my_turn: boolean;
}

export interface EnvelopeItem {
    id: string;
    title: string;
    mode: string;
    status: string;
    verification_token: string;
    verify_url: string;
    qr_svg: string;
    creator_name: string | null;
    has_certificate_pdf: boolean;
    completed_at: string | null;
    requests: SignatureRequestItem[];
}

export interface SignatureVerificationPayload {
    overall_status: string;
    signatures_count: number;
    verified_at: string | null;
    details: {
        signatures?: {
            field_name?: string;
            kind?: string;
            signer?: string;
            cert_serial?: string;
            intact?: boolean;
            trusted?: boolean;
            revoked?: boolean;
            expired?: boolean;
            modification_level?: string;
            timestamp?: string | null;
            status?: string;
        }[];
        trailing_bytes_altered?: boolean;
        note?: string;
    };
}

const props = defineProps<{
    documentId: string;
    currentVersionId: string | null;
    docNumber: string;
    verification: SignatureVerificationPayload;
    envelopes: EnvelopeItem[];
    shareableUsers: { id: string; name: string; email: string; rank_title: string | null; clearance_rank: number }[];
    canSign: boolean;
    canShare: boolean;
}>();

const page = usePage<{ props: SharedProps }>();
const sharedProps = computed(() => page.props as unknown as SharedProps);
const currentUser = computed(() => sharedProps.value.auth?.user ?? null);

const showNewEnvelope = ref(false);
const activeSignRequestId = ref<string | null>(null);
const declineRequestId = ref<string | null>(null);
const reassignRequestId = ref<string | null>(null);
const showStepUpBox = ref(false);
const mfaErrorMessage = ref<string | null>(null);
const stepUpCode = ref('');

const candidateSigners = computed(() => {
    const list: { id: string; label: string }[] = [];
    if (currentUser.value) {
        list.push({
            id: currentUser.value.id,
            label: `${currentUser.value.rank_title ? currentUser.value.rank_title + ' ' : ''}${currentUser.value.name} (Self)`,
        });
    }
    for (const u of props.shareableUsers) {
        list.push({
            id: u.id,
            label: `${u.rank_title ? u.rank_title + ' ' : ''}${u.name} (L${u.clearance_rank})`,
        });
    }
    return list;
});

const envelopeForm = useForm({
    title: `Sign & Seal — ${props.docNumber}`,
    mode: 'ordered',
    message: '',
    signers: [
        {
            signer_id: currentUser.value?.id ?? props.shareableUsers[0]?.id ?? '',
            sign_order: 1,
            tier: 'tier2_pades',
            field_coords: { page: 1, x: 54, y: 580, width: 195, height: 58 },
        },
    ],
});

const signForm = useForm({
    reason: 'Approved & executed under organisational authority',
    location: 'Harare HQ LAN',
    tier: 'tier2_pades',
    typed_name: currentUser.value?.name ?? '',
    drawn_stamp_svg: '',
});

const declineForm = useForm({ reason: '' });
const reassignForm = useForm({ new_signer_id: props.shareableUsers[0]?.id ?? '' });

function addSignerSlot(): void {
    const nextOrder = envelopeForm.signers.length + 1;
    const defaultUser = props.shareableUsers[envelopeForm.signers.length - 1]?.id ?? candidateSigners.value[0]?.id ?? '';
    envelopeForm.signers.push({
        signer_id: defaultUser,
        sign_order: nextOrder,
        tier: 'tier2_pades',
        field_coords: {
            page: 1,
            x: 54 + ((nextOrder - 1) % 2) * 230,
            y: 580 + Math.floor((nextOrder - 1) / 2) * 74,
            width: 195,
            height: 58,
        },
    });
}

function positionClick(slotIndex: number, ev: MouseEvent): void {
    const target = ev.currentTarget as HTMLElement | null;
    if (!target) {
        return;
    }
    const rect = target.getBoundingClientRect();
    const xPct = (ev.clientX - rect.left) / rect.width;
    const yPct = (ev.clientY - rect.top) / rect.height;
    const slot = envelopeForm.signers[slotIndex];
    if (slot) {
        slot.field_coords.x = Math.round(xPct * 595);
        slot.field_coords.y = Math.round(yPct * 792);
    }
}

function submitEnvelope(): void {
    envelopeForm.post(`/documents/${props.documentId}/envelopes`, {
        preserveScroll: true,
        onSuccess: () => {
            showNewEnvelope.value = false;
        },
    });
}

function openSignPanel(req: SignatureRequestItem): void {
    activeSignRequestId.value = req.id;
    signForm.tier = req.tier === 'tier2_smartcard' ? 'tier2_pades' : req.tier;
    signForm.clearErrors();
}

function submitSign(reqId: string): void {
    mfaErrorMessage.value = null;
    signForm.post(`/signatures/requests/${reqId}/sign`, {
        preserveScroll: true,
        onSuccess: () => {
            activeSignRequestId.value = null;
            mfaErrorMessage.value = null;
        },
        onError: (errors: Record<string, string>) => {
            if (errors.mfa) {
                showStepUpBox.value = true;
                mfaErrorMessage.value = errors.mfa;
            }
        },
    });
}

function verifyStepUp(): void {
    router.post(
        '/security/mfa/step-up',
        { code: stepUpCode.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                stepUpCode.value = '';
                showStepUpBox.value = false;
                mfaErrorMessage.value = null;
            },
        }
    );
}

function submitDecline(reqId: string): void {
    declineForm.post(`/signatures/requests/${reqId}/decline`, {
        preserveScroll: true,
        onSuccess: () => {
            declineRequestId.value = null;
            declineForm.reset();
        },
    });
}

function submitReassign(reqId: string): void {
    reassignForm.post(`/signatures/requests/${reqId}/reassign`, {
        preserveScroll: true,
        onSuccess: () => {
            reassignRequestId.value = null;
        },
    });
}

function sendReminder(reqId: string): void {
    router.post(`/signatures/requests/${reqId}/remind`, {}, { preserveScroll: true });
}

function runVerification(): void {
    if (!props.currentVersionId) {
        return;
    }
    router.post(`/documents/${props.documentId}/versions/${props.currentVersionId}/verify`, {}, { preserveScroll: true });
}

const bannerStyle = computed(() => {
    const s = props.verification.overall_status;
    if (s === 'valid') {
        return { box: 'border-emerald-300 bg-emerald-50 text-emerald-950', badge: 'bg-emerald-700 text-white', label: 'VALID PAdES SIGNATURES' };
    }
    if (s === 'altered') {
        return { box: 'border-red-400 bg-red-50 text-red-950', badge: 'bg-red-700 text-white', label: 'ALTERED / TAMPER DETECTED' };
    }
    if (s === 'revoked') {
        return { box: 'border-rose-400 bg-rose-50 text-rose-950', badge: 'bg-rose-700 text-white', label: 'CERTIFICATE REVOKED' };
    }
    if (s === 'expired') {
        return { box: 'border-amber-300 bg-amber-50 text-amber-950', badge: 'bg-amber-700 text-white', label: 'CERTIFICATE EXPIRED' };
    }
    return { box: 'border-slate-200 bg-white text-slate-800', badge: 'bg-slate-600 text-white', label: 'UNSIGNED DOCUMENT' };
});
</script>

<template>
    <div class="space-y-5">
        <!-- Automatic Open-Time Verification Banner -->
        <div class="rounded-xl border p-4 shadow-xs" :class="bannerStyle.box">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <ShieldCheck v-if="verification.overall_status === 'valid'" class="h-6 w-6 shrink-0 text-emerald-700" />
                    <ShieldAlert v-else-if="verification.overall_status !== 'unsigned'" class="h-6 w-6 shrink-0 text-red-700" />
                    <PenTool v-else class="h-5 w-5 shrink-0 text-slate-500" />
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="rounded px-2 py-0.5 text-[11px] font-bold uppercase tracking-wider" :class="bannerStyle.badge">
                                {{ bannerStyle.label }}
                            </span>
                            <span class="text-xs font-semibold">
                                {{ verification.signatures_count }} embedded PAdES / TSA field(s)
                            </span>
                        </div>
                        <p class="mt-0.5 text-[11px] opacity-80">
                            Verified offline against Tenant Root CA + Local CRL/OCSP
                            <span v-if="verification.verified_at"> · Last checked {{ new Date(verification.verified_at).toLocaleString() }}</span>
                        </p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <button
                        v-if="currentVersionId"
                        type="button"
                        @click="runVerification"
                        class="inline-flex items-center gap-1 rounded-lg border border-current/25 bg-white/80 px-2.5 py-1.5 text-xs font-semibold hover:bg-white"
                    >
                        <RefreshCw class="h-3.5 w-3.5" /> Re-verify
                    </button>
                    <button
                        v-if="canSign || canShare"
                        type="button"
                        @click="showNewEnvelope = !showNewEnvelope"
                        class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800"
                    >
                        <PenTool class="h-3.5 w-3.5" /> Initiate Signing
                    </button>
                </div>
            </div>

            <div v-if="verification.details?.signatures?.length" class="mt-3 space-y-1.5 border-t border-current/15 pt-3 text-xs">
                <div
                    v-for="(sig, idx) in verification.details.signatures"
                    :key="idx"
                    class="flex flex-wrap items-center justify-between gap-2 rounded bg-white/75 px-3 py-1.5 font-mono text-[11px]"
                >
                    <span>
                        <strong>{{ sig.field_name }}</strong>
                        <span v-if="sig.signer"> — {{ sig.signer }}</span>
                        <span v-if="sig.cert_serial"> (Serial {{ sig.cert_serial.slice(0, 12) }}...)</span>
                    </span>
                    <span class="font-bold uppercase" :class="sig.status === 'valid' ? 'text-emerald-700' : 'text-red-700'">
                        {{ sig.status }} · intact={{ sig.intact }} · mod={{ sig.modification_level }}
                    </span>
                </div>
            </div>
        </div>

        <!-- New Multi-Party / Single Signing Envelope Designer -->
        <div v-if="showNewEnvelope" class="rounded-xl border border-sky-200 bg-white p-5 shadow-xs">
            <h3 class="mb-3 text-sm font-bold text-slate-900">Configure Signing Envelope &amp; Place PDF Signature Fields</h3>
            <form @submit.prevent="submitEnvelope" class="space-y-4">
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-700">Envelope Title</label>
                        <input v-model="envelopeForm.title" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-semibold text-slate-700">Signing Order Mode</label>
                        <select v-model="envelopeForm.mode" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                            <option value="ordered">Ordered (Sequential Turn-by-Turn)</option>
                            <option value="parallel">Parallel (All Signers Concurrent)</option>
                        </select>
                    </div>
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500">Signers &amp; PDF Field Placement</span>
                        <button type="button" @click="addSignerSlot" class="rounded bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-800 hover:bg-slate-200">
                            + Add Signer
                        </button>
                    </div>

                    <div v-for="(slot, idx) in envelopeForm.signers" :key="idx" class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-2">
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <select v-model="slot.signer_id" class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                                <option v-for="u in candidateSigners" :key="u.id" :value="u.id">{{ u.label }}</option>
                            </select>
                            <select v-model="slot.tier" class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                                <option value="tier2_pades">Tier 2: X.509 PAdES-B-LT (HSM + TSA)</option>
                                <option value="tier1_stamp">Tier 1: Drawn / Typed Visual Stamp</option>
                                <option value="tier3_workflow">Tier 3: Workflow Approval Signature</option>
                            </select>
                            <div class="flex items-center gap-2 text-xs font-mono">
                                <span>Order #</span>
                                <input v-model.number="slot.sign_order" type="number" min="1" max="20" class="w-16 rounded border border-slate-300 bg-white px-2 py-1" />
                                <span>Pg {{ slot.field_coords.page }} ({{ slot.field_coords.x }}, {{ slot.field_coords.y }})</span>
                            </div>
                        </div>
                        <div
                            @click="positionClick(idx, $event)"
                            class="relative h-20 cursor-crosshair rounded border border-dashed border-sky-400 bg-white text-[10px] text-slate-400"
                            title="Click anywhere inside preview sheet to place PDF signature field coordinates"
                        >
                            <span class="p-1.5 block">Click page sheet preview to position Signer #{{ idx + 1 }} PDF box</span>
                            <div
                                class="pointer-events-none absolute rounded border border-sky-700 bg-sky-100/80 px-1.5 py-0.5 font-mono text-[10px] font-bold text-sky-900"
                                :style="{ left: `${Math.min(82, (slot.field_coords.x / 595) * 100)}%`, top: `${Math.min(72, (slot.field_coords.y / 792) * 100)}%` }"
                            >
                                Sig #{{ idx + 1 }}
                            </div>
                        </div>
                    </div>
                    <p v-if="envelopeForm.errors.signers" class="text-xs font-semibold text-red-600">{{ envelopeForm.errors.signers }}</p>
                </div>

                <div class="flex justify-end gap-2">
                    <button type="button" @click="showNewEnvelope = false" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs font-semibold text-slate-700">
                        Cancel
                    </button>
                    <button type="submit" :disabled="envelopeForm.processing" class="rounded-lg bg-sky-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-sky-500">
                        Launch Signing Envelope
                    </button>
                </div>
            </form>
        </div>

        <!-- Active & Sealed Envelopes List -->
        <div v-for="env in envelopes" :key="env.id" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-4">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-bold text-slate-900">{{ env.title }}</h3>
                        <span
                            class="rounded-full px-2.5 py-0.5 text-[11px] font-bold uppercase"
                            :class="env.status === 'completed' ? 'bg-emerald-100 text-emerald-900' : env.status === 'declined' ? 'bg-red-100 text-red-900' : 'bg-amber-100 text-amber-900'"
                        >
                            {{ env.mode }} · {{ env.status }}
                        </span>
                    </div>
                    <p class="mt-0.5 text-xs text-slate-500">
                        Initiated by {{ env.creator_name ?? 'Officer' }}
                        <span v-if="env.completed_at"> · Sealed {{ new Date(env.completed_at).toLocaleString() }}</span>
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <a
                        v-if="env.has_certificate_pdf"
                        :href="`/signatures/envelopes/${env.id}/certificate`"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-emerald-50 px-3 py-1.5 text-xs font-semibold text-emerald-900 hover:bg-emerald-100"
                    >
                        <FileDown class="h-3.5 w-3.5" /> Certificate Page PDF
                    </a>
                    <a
                        v-if="env.status === 'completed'"
                        :href="env.verify_url"
                        target="_blank"
                        class="block rounded border border-slate-200 bg-white p-1"
                        title="Open Offline Verification Portal"
                        v-html="env.qr_svg"
                    />
                </div>
            </div>

            <div class="divide-y divide-slate-100">
                <div v-for="req in env.requests" :key="req.id" class="py-3 space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2 text-xs">
                        <div class="flex items-center gap-2.5">
                            <CheckCircle2 v-if="req.status === 'signed'" class="h-4 w-4 text-emerald-600" />
                            <XCircle v-else-if="req.status === 'declined'" class="h-4 w-4 text-red-600" />
                            <PenTool v-else class="h-4 w-4 text-sky-600" />
                            <div>
                                <span class="font-semibold text-slate-900">#{{ req.sign_order }} {{ req.signer_name }}</span>
                                <span class="ml-2 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-700">{{ req.tier }}</span>
                                <span class="ml-2 font-mono text-[11px] uppercase text-slate-500">{{ req.status }}</span>
                                <div v-if="req.cert_serial || req.signed_at" class="font-mono text-[11px] text-slate-500">
                                    <span v-if="req.cert_serial">Cert: {{ req.cert_serial }}</span>
                                    <span v-if="req.tsa_token_serial"> · TSA: {{ req.tsa_token_serial }}</span>
                                    <span v-if="req.signed_at"> · Signed {{ new Date(req.signed_at).toLocaleString() }}</span>
                                </div>
                                <p v-if="req.decline_reason" class="text-red-700">Declined: {{ req.decline_reason }}</p>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                v-if="req.is_my_turn"
                                type="button"
                                @click="openSignPanel(req)"
                                class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-500"
                            >
                                Sign Now
                            </button>
                            <button
                                v-if="req.is_my_turn"
                                type="button"
                                @click="declineRequestId = declineRequestId === req.id ? null : req.id"
                                class="rounded-lg border border-red-200 px-2.5 py-1 text-xs font-semibold text-red-700 hover:bg-red-50"
                            >
                                Decline
                            </button>
                            <button
                                v-if="req.status === 'ready' || req.status === 'pending'"
                                type="button"
                                @click="reassignRequestId = reassignRequestId === req.id ? null : req.id"
                                class="rounded-lg border border-slate-300 px-2.5 py-1 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                Reassign
                            </button>
                            <button
                                v-if="req.status === 'ready' || req.status === 'pending'"
                                type="button"
                                @click="sendReminder(req.id)"
                                class="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1 text-xs text-slate-700 hover:bg-slate-50"
                            >
                                <Send class="h-3 w-3" /> Remind ({{ req.reminder_count }})
                            </button>
                        </div>
                    </div>

                    <!-- Inline Signing Pad / HSM PAdES Drawer -->
                    <div v-if="activeSignRequestId === req.id" class="rounded-lg border border-emerald-200 bg-emerald-50/40 p-4 space-y-3">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <select v-model="signForm.tier" class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                                <option value="tier2_pades">Tier 2: X.509 PAdES-B-LT (HSM + TSA)</option>
                                <option value="tier1_stamp">Tier 1: Drawn / Typed Visual Stamp</option>
                                <option value="tier3_workflow">Tier 3: Workflow Approval Signature</option>
                            </select>
                            <input v-model="signForm.reason" type="text" placeholder="Signing reason" class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs" />
                            <input v-model="signForm.location" type="text" placeholder="Location" class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs" />
                        </div>

                        <div v-if="signForm.tier === 'tier1_stamp'" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <input v-model="signForm.typed_name" type="text" placeholder="Typed full name / rank" class="h-10 rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs" />
                            <SignaturePadCanvas v-model="signForm.drawn_stamp_svg" />
                        </div>

                        <div v-if="mfaErrorMessage || showStepUpBox" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-xs space-y-2">
                            <p class="font-semibold text-amber-900">{{ mfaErrorMessage ?? 'Confirm TOTP code to unlock your HSM signing key (valid 5 min):' }}</p>
                            <div class="flex gap-2">
                                <input v-model="stepUpCode" type="text" placeholder="6-digit TOTP or recovery code" class="rounded border border-amber-400 bg-white px-2.5 py-1 font-mono" />
                                <button type="button" @click="verifyStepUp" class="inline-flex items-center gap-1 rounded bg-amber-700 px-3 py-1 font-semibold text-white">
                                    <KeyRound class="h-3.5 w-3.5" /> Unlock Signing Key
                                </button>
                            </div>
                        </div>

                        <div class="flex justify-end gap-2">
                            <button type="button" @click="activeSignRequestId = null" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold">Cancel</button>
                            <button type="button" :disabled="signForm.processing" @click="submitSign(req.id)" class="rounded bg-emerald-700 px-4 py-1.5 text-xs font-semibold text-white hover:bg-emerald-600">
                                Apply Digital Signature
                            </button>
                        </div>
                    </div>

                    <!-- Decline Box -->
                    <div v-if="declineRequestId === req.id" class="flex gap-2 rounded bg-red-50 p-3 text-xs">
                        <input v-model="declineForm.reason" type="text" placeholder="Reason for declining (min 5 chars)" class="flex-1 rounded border border-red-300 bg-white px-2.5 py-1" />
                        <button type="button" @click="submitDecline(req.id)" class="rounded bg-red-700 px-3 py-1 font-semibold text-white">Confirm Decline</button>
                    </div>

                    <!-- Reassign Box -->
                    <div v-if="reassignRequestId === req.id" class="space-y-1.5 rounded bg-slate-50 p-3 text-xs">
                        <div class="flex gap-2">
                            <select v-model="reassignForm.new_signer_id" class="flex-1 rounded border border-slate-300 bg-white px-2.5 py-1">
                                <option v-for="u in shareableUsers" :key="u.id" :value="u.id">{{ u.name }} (L{{ u.clearance_rank }})</option>
                            </select>
                            <button type="button" @click="submitReassign(req.id)" class="rounded bg-slate-900 px-3 py-1 font-semibold text-white">Confirm Reassign</button>
                        </div>
                        <p v-if="reassignForm.errors.new_signer_id" class="font-semibold text-red-600">{{ reassignForm.errors.new_signer_id }}</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
