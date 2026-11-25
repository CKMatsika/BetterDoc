<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, FileCheck2, Lock, PenTool, ShieldCheck, UploadCloud } from '@lucide/vue';

interface PublicPortalRequest {
    request_type: 'upload' | 'esign' | 'upload_and_esign';
    title: string;
    instructions: string | null;
    recipient_name: string;
    policy_number: string | null;
    claim_number: string | null;
    document_title: string | null;
    document_number: string | null;
    status: string;
    requires_otp: boolean;
    uploads_count: number;
    max_uploads: number;
    signed_at: string | null;
    signed_by_name: string | null;
    signature_hash: string | null;
    expires_at: string;
}

const props = defineProps<{
    token: string;
    branding: {
        name: string;
        short_name: string;
        logo_url: string | null;
        primary_color: string;
    };
    portalRequest: PublicPortalRequest;
}>();

const page = usePage<{ flash?: { success?: string; error?: string } }>();
const flashSuccess = computed(() => page.props.flash?.success ?? null);
const sharedOtp = ref('');

const uploadForm = useForm<{ file: File | null; otp: string; uploader_note: string }>({
    file: null,
    otp: '',
    uploader_note: '',
});

const signForm = useForm({
    signer_name: props.portalRequest.recipient_name,
    signer_id_number: '',
    otp: '',
});

const isUsable = computed(() => props.portalRequest.status === 'active');
const canUpload = computed(
    () =>
        isUsable.value &&
        (props.portalRequest.request_type === 'upload' || props.portalRequest.request_type === 'upload_and_esign') &&
        props.portalRequest.uploads_count < props.portalRequest.max_uploads,
);
const canSign = computed(
    () =>
        isUsable.value &&
        (props.portalRequest.request_type === 'esign' || props.portalRequest.request_type === 'upload_and_esign') &&
        !props.portalRequest.signed_at,
);

function onFileChange(e: Event): void {
    const input = e.target as HTMLInputElement;
    uploadForm.file = input.files?.[0] ?? null;
}

function submitUpload(): void {
    uploadForm.otp = sharedOtp.value;
    uploadForm.post(`/portal/${props.token}/upload`, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            uploadForm.reset('file', 'uploader_note');
        },
    });
}

function submitSignature(): void {
    signForm.otp = sharedOtp.value;
    signForm.post(`/portal/${props.token}/sign`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`${portalRequest.title} — ${branding.name}`" />

    <div class="min-h-screen bg-slate-50 px-4 py-8 text-slate-900">
        <div class="mx-auto max-w-xl space-y-5">
            <!-- Branded Organisation Header -->
            <div class="rounded-2xl bg-slate-900 p-5 text-white shadow-sm">
                <div class="flex items-center gap-3">
                    <img
                        :src="branding.logo_url || '/betterdoc-icon.svg'"
                        :alt="branding.name"
                        class="h-10 w-10 rounded-lg bg-white/10 object-contain p-1"
                    />
                    <div>
                        <p class="text-sm font-bold">{{ branding.name }}</p>
                        <p class="text-[11px] text-slate-300">Encrypted External Client &amp; Supplier Portal</p>
                    </div>
                </div>
            </div>

            <!-- Request Details Card -->
            <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-xs space-y-3">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <h1 class="text-base font-bold text-slate-900">{{ portalRequest.title }}</h1>
                        <p class="text-xs text-slate-600">Prepared for <strong>{{ portalRequest.recipient_name }}</strong></p>
                    </div>
                    <span
                        class="rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase"
                        :class="
                            portalRequest.status === 'active'
                                ? 'bg-emerald-100 text-emerald-800'
                                : portalRequest.status === 'completed'
                                  ? 'bg-sky-100 text-sky-800'
                                  : 'bg-rose-100 text-rose-800'
                        "
                    >
                        {{ portalRequest.status }}
                    </span>
                </div>

                <p v-if="portalRequest.instructions" class="rounded-xl bg-slate-50 p-3 text-xs text-slate-700">
                    {{ portalRequest.instructions }}
                </p>

                <div class="flex flex-wrap gap-2 text-[11px] font-mono text-slate-600">
                    <span v-if="portalRequest.policy_number" class="rounded bg-slate-100 px-2 py-0.5">
                        Policy: {{ portalRequest.policy_number }}
                    </span>
                    <span v-if="portalRequest.claim_number" class="rounded bg-slate-100 px-2 py-0.5">
                        Claim: {{ portalRequest.claim_number }}
                    </span>
                    <span v-if="portalRequest.document_number" class="rounded bg-slate-100 px-2 py-0.5">
                        Doc: {{ portalRequest.document_number }}
                    </span>
                </div>

                <div
                    v-if="flashSuccess"
                    class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-xs font-semibold text-emerald-900"
                >
                    <CheckCircle2 class="h-4 w-4 shrink-0 text-emerald-700" />
                    <span>{{ flashSuccess }}</span>
                </div>

                <!-- Optional 6-digit Security PIN -->
                <div v-if="portalRequest.requires_otp && isUsable" class="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs">
                    <label class="mb-1 flex items-center gap-1.5 font-bold text-amber-900">
                        <Lock class="h-3.5 w-3.5" /> 6-Digit Security PIN Required
                    </label>
                    <input
                        v-model="sharedOtp"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        placeholder="Enter 6-digit PIN shared with you"
                        class="w-full rounded-lg border border-amber-300 bg-white px-3 py-2 font-mono text-sm tracking-widest"
                    />
                </div>
            </div>

            <!-- UPLOAD SECTION -->
            <form
                v-if="canUpload"
                @submit.prevent="submitUpload"
                class="rounded-2xl border border-slate-200 bg-white p-5 text-xs shadow-xs space-y-3"
            >
                <div class="flex items-center justify-between">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <UploadCloud class="h-4 w-4 text-sky-600" /> Upload Document / Photo
                    </h2>
                    <span class="font-mono text-[11px] text-slate-500">
                        {{ portalRequest.uploads_count }} / {{ portalRequest.max_uploads }} uploaded
                    </span>
                </div>
                <input
                    type="file"
                    accept="image/*,.pdf"
                    capture="environment"
                    required
                    @change="onFileChange"
                    class="w-full rounded-xl border border-slate-300 p-2.5"
                />
                <input
                    v-model="uploadForm.uploader_note"
                    type="text"
                    placeholder="Optional note (e.g. National ID, Police Report, Tax Invoice)"
                    class="w-full rounded-xl border border-slate-300 px-3 py-2"
                />
                <p v-if="uploadForm.errors.file || (uploadForm.errors as Record<string, string>).portal" class="font-semibold text-rose-600">
                    {{ uploadForm.errors.file || (uploadForm.errors as Record<string, string>).portal }}
                </p>
                <button
                    type="submit"
                    :disabled="!uploadForm.file || uploadForm.processing"
                    class="w-full rounded-xl bg-sky-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-sky-700 disabled:opacity-50"
                >
                    {{ uploadForm.processing ? 'Scanning & Encrypting...' : 'Securely Upload File' }}
                </button>
            </form>

            <!-- E-SIGN SECTION -->
            <form
                v-if="canSign"
                @submit.prevent="submitSignature"
                class="rounded-2xl border border-slate-200 bg-white p-5 text-xs shadow-xs space-y-3"
            >
                <h2 class="flex items-center gap-2 text-sm font-bold text-slate-900">
                    <PenTool class="h-4 w-4 text-emerald-700" /> Tamper-Evident Electronic Signature
                </h2>
                <p v-if="portalRequest.document_title" class="rounded-lg bg-slate-50 px-3 py-2 text-slate-700">
                    Signing Document: <strong>{{ portalRequest.document_title }}</strong>
                </p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <label class="mb-1 block font-semibold text-slate-700">Full Legal Name</label>
                        <input v-model="signForm.signer_name" type="text" required class="w-full rounded-xl border border-slate-300 px-3 py-2" />
                    </div>
                    <div>
                        <label class="mb-1 block font-semibold text-slate-700">National ID / Passport Number</label>
                        <input
                            v-model="signForm.signer_id_number"
                            type="text"
                            required
                            placeholder="e.g. 63-123456-A-42"
                            class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono"
                        />
                    </div>
                </div>
                <p v-if="(signForm.errors as Record<string, string>).portal" class="font-semibold text-rose-600">
                    {{ (signForm.errors as Record<string, string>).portal }}
                </p>
                <button
                    type="submit"
                    :disabled="signForm.processing"
                    class="w-full rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-700 disabled:opacity-50"
                >
                    {{ signForm.processing ? 'Applying Cryptographic Seal...' : 'Confirm & Electronically Sign' }}
                </button>
            </form>

            <!-- COMPLETED SIGNATURE RECEIPT -->
            <div v-if="portalRequest.signed_at" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-xs space-y-2">
                <div class="flex items-center gap-2 font-bold text-emerald-900">
                    <FileCheck2 class="h-4 w-4" /> Electronic Signature Sealed
                </div>
                <p>Signed by <strong>{{ portalRequest.signed_by_name }}</strong> on {{ new Date(portalRequest.signed_at).toLocaleString() }}</p>
                <p class="break-all font-mono text-[10px] text-emerald-800">SHA-256 Seal: {{ portalRequest.signature_hash }}</p>
            </div>

            <div class="flex items-center justify-center gap-1.5 text-[11px] text-slate-500">
                <ShieldCheck class="h-3.5 w-3.5 text-emerald-600" />
                <span>Protected by BetterDoc DMS · ClamAV Malware Scan · XChaCha20-Poly1305 Vault Encryption</span>
            </div>
        </div>
    </div>
</template>
