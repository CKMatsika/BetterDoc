<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, AlertTriangle, Download, GitCompare, Lock, RotateCcw, Share2, Shield, Trash2, Unlock } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClassificationBadge from '@/Components/ClassificationBadge.vue';
import PdfWatermarkViewer from '@/Components/PdfWatermarkViewer.vue';
import ResumableUploader from '@/Components/ResumableUploader.vue';
import SigningPanel, { type EnvelopeItem, type SignatureVerificationPayload } from '@/Components/SigningPanel.vue';

interface VersionRow {
    id: string;
    label: string;
    version_major: number;
    version_minor: number;
    is_current: boolean;
    original_filename: string;
    mime_type: string;
    file_size_bytes: number;
    sha256_hash: string;
    av_status: string;
    cdr_status: string;
    ocr_status: string;
    page_count: number;
    version_note: string | null;
    creator_name: string | null;
    created_at: string | null;
}

const props = defineProps<{
    document: {
        id: string;
        doc_number: string;
        title: string;
        summary: string | null;
        status: string;
        classification_rank: number;
        current_version_id: string | null;
        classification: {
            id: string;
            code: string;
            name: string;
            color_hex: string;
            watermark_mode: string;
            two_person_required: boolean;
        };
        cabinet_name: string | null;
        org_unit_name: string | null;
        owner_name: string | null;
        compartments: string[];
        tags: { id: string; name: string; color_hex: string }[];
        legal_hold_active: boolean;
        is_worm: boolean;
        lock: {
            locked_by_id: string;
            locked_by_name: string | null;
            checkout_note: string | null;
            expires_at: string;
            is_mine: boolean;
        } | null;
        correspondence: {
            registry_ref_no: string;
            direction: string;
            status: string;
        } | null;
        versions: VersionRow[];
        grants: {
            id: string;
            principal_type: string;
            principal_id: string;
            perm_view: boolean;
            perm_download: boolean;
            perm_print: boolean;
            perm_sign: boolean;
            perm_edit: boolean;
        }[];
    };
    watermarkOverlay: {
        viewer: string;
        service_number: string;
        classification: string;
        ip: string;
        timestamp: string;
        break_glass: boolean;
    };
    isBreakGlass: boolean;
    shareableUsers: { id: string; name: string; email: string; rank_title: string | null; clearance_rank: number }[];
    clearanceLevels: { id: string; rank: number; code: string; name: string; color_hex: string }[];
    signatureVerification: SignatureVerificationPayload;
    envelopes: EnvelopeItem[];
    capabilities: {
        can_edit: boolean;
        can_share: boolean;
        can_download: boolean;
        can_print: boolean;
        can_sign: boolean;
        can_delete: boolean;
    };
}>();

const activeVersion = ref<VersionRow | null>(
    props.document.versions.find((v) => v.is_current) ?? props.document.versions[0] ?? null
);
const diffFromId = ref(props.document.versions[1]?.id ?? props.document.versions[0]?.id ?? '');
const diffToId = ref(props.document.versions[0]?.id ?? '');
const diffResult = ref<{ type: string; text: string }[] | null>(null);
const showCheckinUploader = ref(false);

const shareForm = useForm({
    principal_type: 'user',
    principal_id: props.shareableUsers[0]?.id ?? '',
    perm_view: true,
    perm_download: false,
    perm_print: false,
    perm_sign: false,
    perm_edit: false,
});

const reclassifyForm = useForm({
    target_classification_level_id: props.document.classification.id,
    reason: '',
});

const tagForm = useForm({ name: '' });

function submitShare(): void {
    shareForm.post(`/documents/${props.document.id}/share`, { preserveScroll: true });
}

function submitReclassify(): void {
    reclassifyForm.post(`/documents/${props.document.id}/reclassify`, {
        preserveScroll: true,
        onSuccess: () => reclassifyForm.reset('reason'),
    });
}

function submitTag(): void {
    tagForm.post(`/documents/${props.document.id}/tags`, {
        preserveScroll: true,
        onSuccess: () => tagForm.reset(),
    });
}

function checkout(): void {
    router.post(`/documents/${props.document.id}/checkout`, { note: 'Checking out for revision' });
}

function checkin(): void {
    router.post(`/documents/${props.document.id}/checkin`);
}

async function runDiff(): Promise<void> {
    if (!diffFromId.value || !diffToId.value) {
        return;
    }
    const res = await fetch(
        `/documents/${props.document.id}/compare?from_version_id=${diffFromId.value}&to_version_id=${diffToId.value}`,
        { credentials: 'same-origin' }
    );
    if (res.ok) {
        const data = (await res.json()) as { diff: { type: string; text: string }[] };
        diffResult.value = data.diff;
    }
}

function restoreVersion(versionId: string): void {
    router.post(`/documents/${props.document.id}/versions/${versionId}/restore`);
}

function requestDelete(): void {
    router.delete(`/documents/${props.document.id}`, {
        data: { reason: 'Authorized record lifecycle removal request.' },
    });
}
</script>

<template>
    <Head :title="document.doc_number" />
    <AppLayout>
        <div class="space-y-6">
            <div v-if="isBreakGlass" class="flex items-center gap-3 rounded-xl border-2 border-red-600 bg-red-950 px-5 py-3 text-white shadow-md">
                <AlertTriangle class="h-5 w-5 shrink-0 text-amber-400" />
                <div class="text-xs">
                    <p class="font-bold uppercase tracking-wider">BREAK-GLASS EMERGENCY ACCESS ACTIVE</p>
                    <p class="text-red-200">Every view and action on this document is actively watermarked and alerted to Security Officers &amp; Auditors.</p>
                </div>
            </div>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <Link href="/documents" class="mb-2 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-900">
                        <ArrowLeft class="h-3.5 w-3.5" /> Back to Vault
                    </Link>
                    <div class="flex flex-wrap items-center gap-3">
                        <h1 class="text-2xl font-bold text-slate-900">{{ document.title }}</h1>
                        <ClassificationBadge
                            :name="document.classification.name"
                            :color-hex="document.classification.color_hex"
                            :rank="document.classification_rank"
                        />
                    </div>
                    <p class="mt-1 font-mono text-xs text-slate-600">
                        {{ document.doc_number }} · {{ document.cabinet_name }} · {{ document.org_unit_name }} · Owner: {{ document.owner_name }}
                        <span v-if="document.correspondence" class="ml-2 rounded bg-sky-100 px-2 py-0.5 font-semibold text-sky-900">
                            Registry: {{ document.correspondence.registry_ref_no }}
                        </span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button v-if="capabilities.can_edit && !document.lock" type="button" @click="checkout" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50">
                        <Lock class="h-3.5 w-3.5" /> Check Out
                    </button>
                    <button v-if="capabilities.can_edit && document.lock?.is_mine" type="button" @click="checkin" class="inline-flex items-center gap-1.5 rounded-lg bg-amber-600 px-3 py-2 text-xs font-semibold text-white">
                        <Unlock class="h-3.5 w-3.5" /> Check In Lock
                    </button>
                    <button v-if="capabilities.can_edit" type="button" @click="showCheckinUploader = !showCheckinUploader" class="inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold text-white">
                        Upload New Version
                    </button>
                    <a
                        v-if="capabilities.can_download && activeVersion"
                        :href="`/documents/${document.id}/versions/${activeVersion.id}/download`"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50"
                    >
                        <Download class="h-3.5 w-3.5" /> Download {{ activeVersion.label }}
                    </a>
                    <button
                        v-if="capabilities.can_delete"
                        type="button"
                        @click="requestDelete"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                        <span>{{ document.classification.two_person_required ? '2-Person Delete' : 'Delete' }}</span>
                    </button>
                </div>
            </div>

            <div v-if="showCheckinUploader">
                <ResumableUploader :document-id="document.id" @completed="() => router.reload()" />
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="space-y-6 lg:col-span-2">
                    <!-- Self-Hosted PDF.js Viewer with Forensic Watermark -->
                    <PdfWatermarkViewer
                        v-if="activeVersion"
                        :stream-url="`/documents/${document.id}/versions/${activeVersion.id}/stream`"
                        :mime-type="activeVersion.mime_type"
                        :watermark="watermarkOverlay"
                    />

                    <!-- Cryptographic Verification Banner & Multi-Party PAdES Envelopes -->
                    <SigningPanel
                        :document-id="document.id"
                        :current-version-id="document.current_version_id"
                        :doc-number="document.doc_number"
                        :verification="signatureVerification"
                        :envelopes="envelopes"
                        :shareable-users="shareableUsers"
                        :can-sign="capabilities.can_sign"
                        :can-share="capabilities.can_share"
                    />

                    <!-- Version History & Compare -->
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-500">Version History &amp; Integrity</h2>
                            <div v-if="document.versions.length >= 2" class="flex items-center gap-2 text-xs">
                                <select v-model="diffFromId" class="rounded border border-slate-300 px-2 py-1 font-mono">
                                    <option v-for="v in document.versions" :key="v.id" :value="v.id">{{ v.label }}</option>
                                </select>
                                <span>vs</span>
                                <select v-model="diffToId" class="rounded border border-slate-300 px-2 py-1 font-mono">
                                    <option v-for="v in document.versions" :key="v.id" :value="v.id">{{ v.label }}</option>
                                </select>
                                <button type="button" @click="runDiff" class="inline-flex items-center gap-1 rounded bg-slate-900 px-2.5 py-1 font-semibold text-white">
                                    <GitCompare class="h-3.5 w-3.5" /> Diff
                                </button>
                            </div>
                        </div>

                        <p v-if="document.versions.length === 0" class="text-xs text-slate-500">No file versions uploaded yet.</p>
                        <div v-else class="divide-y divide-slate-100 text-xs">
                            <div v-for="v in document.versions" :key="v.id" class="flex flex-wrap items-center justify-between gap-3 py-2.5">
                                <div>
                                    <button type="button" @click="activeVersion = v" class="font-mono font-bold text-sky-700 hover:underline">
                                        {{ v.label }} {{ v.is_current ? '(Current)' : '' }}
                                    </button>
                                    <span class="ml-2 text-slate-700">{{ v.original_filename }}</span>
                                    <div class="font-mono text-[11px] text-slate-500">
                                        AV: {{ v.av_status }} · CDR: {{ v.cdr_status }} · OCR: {{ v.ocr_status }} · SHA-256: {{ v.sha256_hash.slice(0, 14) }}...
                                    </div>
                                </div>
                                <button
                                    v-if="capabilities.can_edit && !v.is_current"
                                    type="button"
                                    @click="restoreVersion(v.id)"
                                    class="inline-flex items-center gap-1 rounded border border-slate-300 px-2.5 py-1 font-medium text-slate-700 hover:bg-slate-50"
                                >
                                    <RotateCcw class="h-3 w-3" /> Restore
                                </button>
                            </div>
                        </div>

                        <div v-if="diffResult" class="mt-4 max-h-60 overflow-auto rounded-lg border border-slate-200 bg-slate-950 p-3 font-mono text-xs">
                            <div
                                v-for="(line, i) in diffResult"
                                :key="i"
                                :class="line.type === 'added' ? 'bg-emerald-950/80 text-emerald-300' : line.type === 'removed' ? 'bg-red-950/80 text-red-300' : 'text-slate-400'"
                            >
                                {{ line.type === 'added' ? '+ ' : line.type === 'removed' ? '- ' : '  ' }}{{ line.text }}
                            </div>
                        </div>
                    </div>

                    <!-- Reclassify -->
                    <div v-if="capabilities.can_edit" class="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                        <div class="mb-3 flex items-center gap-2 text-sm font-bold text-slate-900">
                            <Shield class="h-4 w-4 text-amber-600" />
                            <span>Reclassify / Declassify (Bell-LaPadula &amp; Two-Person Enforced)</span>
                        </div>
                        <form @submit.prevent="submitReclassify" class="space-y-3">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <select v-model="reclassifyForm.target_classification_level_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                    <option v-for="lvl in clearanceLevels" :key="lvl.id" :value="lvl.id">L{{ lvl.rank }} — {{ lvl.name }}</option>
                                </select>
                                <input v-model="reclassifyForm.reason" type="text" required placeholder="Reason for classification change (min 10 chars)" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                            </div>
                            <button type="submit" :disabled="reclassifyForm.processing" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                                Submit Classification Change
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Right Column: Metadata, Tags & Share -->
                <div class="space-y-6">
                    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                        <h2 class="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">Metadata &amp; Tags</h2>
                        <p class="text-xs text-slate-700">{{ document.summary ?? 'No summary.' }}</p>
                        <div class="mt-3 flex flex-wrap gap-1.5">
                            <span v-for="t in document.tags" :key="t.id" class="rounded bg-sky-100 px-2 py-0.5 text-[11px] font-medium text-sky-900">#{{ t.name }}</span>
                        </div>
                        <form v-if="capabilities.can_edit" @submit.prevent="submitTag" class="mt-3 flex gap-2">
                            <input v-model="tagForm.name" type="text" required placeholder="Add tag..." class="flex-1 rounded border border-slate-300 px-2 py-1 text-xs" />
                            <button type="submit" class="rounded bg-slate-900 px-2.5 py-1 text-xs font-semibold text-white">Add</button>
                        </form>
                    </div>

                    <div v-if="capabilities.can_share" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                        <div class="mb-3 flex items-center gap-2 text-sm font-bold text-slate-900">
                            <Share2 class="h-4 w-4 text-sky-600" />
                            <span>Share Access (MAC Pre-Checked)</span>
                        </div>
                        <form @submit.prevent="submitShare" class="space-y-3">
                            <select v-model="shareForm.principal_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <option v-for="u in shareableUsers" :key="u.id" :value="u.id">
                                    {{ u.rank_title ? `${u.rank_title} ` : '' }}{{ u.name }} (L{{ u.clearance_rank }})
                                </option>
                            </select>
                            <p v-if="shareForm.errors.principal_id" class="text-xs font-semibold text-red-600">{{ shareForm.errors.principal_id }}</p>
                            <div class="grid grid-cols-2 gap-2 text-xs">
                                <label class="flex items-center gap-1.5"><input type="checkbox" v-model="shareForm.perm_view" /> View</label>
                                <label class="flex items-center gap-1.5"><input type="checkbox" v-model="shareForm.perm_download" /> Download</label>
                                <label class="flex items-center gap-1.5"><input type="checkbox" v-model="shareForm.perm_print" /> Print</label>
                                <label class="flex items-center gap-1.5"><input type="checkbox" v-model="shareForm.perm_sign" /> Sign</label>
                                <label class="flex items-center gap-1.5"><input type="checkbox" v-model="shareForm.perm_edit" /> Edit</label>
                            </div>
                            <button type="submit" :disabled="shareForm.processing" class="w-full rounded-lg bg-sky-600 px-3 py-2 text-xs font-semibold text-white hover:bg-sky-500">
                                Grant Access
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
