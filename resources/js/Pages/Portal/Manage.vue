<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Copy, Link2, MessageCircle, Send, ShieldAlert, XCircle } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface PortalReqRow {
    id: string;
    request_type: string;
    title: string;
    recipient_name: string;
    recipient_email: string | null;
    recipient_phone: string | null;
    policy_number: string | null;
    claim_number: string | null;
    cabinet_name: string;
    document_title: string | null;
    status: string;
    otp_protected: boolean;
    uploads_count: number;
    max_uploads: number;
    signed_at: string | null;
    signed_by_name: string | null;
    signature_hash: string | null;
    expires_at: string;
}

interface WaChannelRow {
    id: string;
    display_name: string;
    phone_number_id: string;
    business_account_id: string | null;
    default_cabinet_name: string;
    auto_idp_invoices: boolean;
    is_active: boolean;
    last_inbound_at: string | null;
}

interface WaMsgRow {
    id: string;
    direction: string;
    from_phone: string;
    to_phone: string | null;
    sender_name: string | null;
    message_type: string;
    body_text: string | null;
    document_id: string | null;
    document_number: string | null;
    status: string;
    created_at: string | null;
}

const props = defineProps<{
    portalRequests: PortalReqRow[];
    waChannels: WaChannelRow[];
    waMessages: WaMsgRow[];
    webhookUrl: string;
    cabinets: { id: string; name: string }[];
    allowedClearances: { id: string; rank: number; name: string }[];
    documents: { id: string; doc_number: string; title: string }[];
    vendors: { id: string; name: string; code: string }[];
    issuedPortalLink: {
        public_url: string;
        plain_otp: string | null;
        recipient_name: string;
        recipient_phone: string | null;
    } | null;
    issuedWaVerifyToken: string | null;
}>();

const activeTab = ref<'portal' | 'whatsapp'>('portal');
const copiedUrl = ref(false);

const portalForm = useForm({
    cabinet_id: props.cabinets[0]?.id ?? '',
    classification_level_id: props.allowedClearances[0]?.id ?? '',
    document_id: '',
    vendor_id: '',
    request_type: 'upload_and_esign',
    title: '',
    instructions: '',
    recipient_name: '',
    recipient_email: '',
    recipient_phone: '',
    policy_number: '',
    claim_number: '',
    require_otp: true,
    max_uploads: 5,
    expires_in_hours: 72,
});

const waChannelForm = useForm({
    display_name: 'Main Claims & AP WhatsApp',
    phone_number_id: '',
    business_account_id: '',
    app_secret: '',
    access_token: '',
    default_cabinet_id: props.cabinets[0]?.id ?? '',
    default_classification_level_id: props.allowedClearances[0]?.id ?? '',
    auto_idp_invoices: true,
});

const waAlertForm = useForm({
    channel_id: props.waChannels[0]?.id ?? '',
    recipient_phone: '',
    subject: '',
    action_url: '',
});

function submitPortalRequest(): void {
    portalForm.post('/external-portal', {
        preserveScroll: true,
        onSuccess: () => portalForm.reset('title', 'instructions', 'recipient_name', 'recipient_email', 'recipient_phone', 'policy_number', 'claim_number'),
    });
}

function revokePortal(id: string): void {
    router.post(`/external-portal/${id}/revoke`, {}, { preserveScroll: true });
}

function submitWaChannel(): void {
    waChannelForm.post('/external-portal/whatsapp/channels', {
        preserveScroll: true,
        onSuccess: () => waChannelForm.reset('phone_number_id', 'app_secret', 'access_token'),
    });
}

function submitWaAlert(): void {
    if (!waAlertForm.channel_id) {
        return;
    }
    waAlertForm.post(`/external-portal/whatsapp/channels/${waAlertForm.channel_id}/alert`, {
        preserveScroll: true,
    });
}

async function copyText(val: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(val);
        copiedUrl.value = true;
        setTimeout(() => {
            copiedUrl.value = false;
        }, 2000);
    } catch {
        // Ignore clipboard errors in restricted contexts
    }
}
</script>

<template>
    <Head title="External Client Portal & WhatsApp Intake" />

    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">External Client Portal &amp; WhatsApp Business Intake</h1>
                    <p class="text-xs text-slate-600">
                        Zero-login magic links for clients, brokers &amp; suppliers (upload + e-sign) and HMAC-verified WhatsApp Business intake.
                    </p>
                </div>
                <div class="flex gap-2 rounded-lg border border-slate-200 bg-white p-1 text-xs font-semibold">
                    <button
                        type="button"
                        @click="activeTab = 'portal'"
                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5"
                        :class="activeTab === 'portal' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                    >
                        <Link2 class="h-3.5 w-3.5" /> Magic-Link Portal ({{ portalRequests.length }})
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'whatsapp'"
                        class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5"
                        :class="activeTab === 'whatsapp' ? 'bg-emerald-700 text-white' : 'text-emerald-700 hover:bg-emerald-50'"
                    >
                        <MessageCircle class="h-3.5 w-3.5" /> WhatsApp Business ({{ waChannels.length }})
                    </button>
                </div>
            </div>

            <!-- ISSUED MAGIC LINK BANNER -->
            <div
                v-if="issuedPortalLink"
                class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs text-emerald-950 space-y-2"
            >
                <div class="flex items-center gap-2 font-bold">
                    <CheckCircle2 class="h-4 w-4 text-emerald-700" />
                    <span>Magic Link Issued for {{ issuedPortalLink.recipient_name }} (Shown Once)</span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <code class="rounded bg-white px-2.5 py-1.5 font-mono text-[11px] border border-emerald-200">
                        {{ issuedPortalLink.public_url }}
                    </code>
                    <button
                        type="button"
                        @click="copyText(issuedPortalLink.public_url)"
                        class="inline-flex items-center gap-1 rounded-lg bg-emerald-700 px-3 py-1.5 font-semibold text-white hover:bg-emerald-800"
                    >
                        <Copy class="h-3.5 w-3.5" /> {{ copiedUrl ? 'Copied!' : 'Copy Link' }}
                    </button>
                </div>
                <p v-if="issuedPortalLink.plain_otp" class="font-mono font-bold text-amber-900">
                    6-Digit Security PIN: {{ issuedPortalLink.plain_otp }} (share via WhatsApp or SMS separately)
                </p>
            </div>

            <!-- TAB 1: EXTERNAL MAGIC-LINK PORTAL -->
            <div v-if="activeTab === 'portal'" class="space-y-6">
                <form @submit.prevent="submitPortalRequest" class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs space-y-4">
                    <h2 class="text-sm font-bold text-slate-900">Create Client / Broker / Supplier Magic-Link Request</h2>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <input v-model="portalForm.title" required placeholder="Request Title (e.g. Motor Claim KYC & Discharge)" class="rounded-lg border border-slate-300 px-3 py-2" />
                        <input v-model="portalForm.recipient_name" required placeholder="Recipient Name" class="rounded-lg border border-slate-300 px-3 py-2" />
                        <select v-model="portalForm.request_type" class="rounded-lg border border-slate-300 px-3 py-2">
                            <option value="upload">Document Upload Only</option>
                            <option value="esign">Electronic Signature Only</option>
                            <option value="upload_and_esign">Upload + E-Sign</option>
                        </select>
                        <input v-model="portalForm.recipient_email" type="email" placeholder="Recipient Email (Optional)" class="rounded-lg border border-slate-300 px-3 py-2" />
                        <input v-model="portalForm.recipient_phone" placeholder="WhatsApp / Phone (+2637...)" class="rounded-lg border border-slate-300 px-3 py-2" />
                        <select v-model="portalForm.cabinet_id" class="rounded-lg border border-slate-300 px-3 py-2">
                            <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <select v-model="portalForm.classification_level_id" class="rounded-lg border border-slate-300 px-3 py-2">
                            <option v-for="l in allowedClearances" :key="l.id" :value="l.id">{{ l.name }}</option>
                        </select>
                        <select v-model="portalForm.document_id" class="rounded-lg border border-slate-300 px-3 py-2">
                            <option value="">Linked Document to E-Sign (Optional)</option>
                            <option v-for="d in documents" :key="d.id" :value="d.id">{{ d.doc_number }} — {{ d.title }}</option>
                        </select>
                        <div class="grid grid-cols-2 gap-2">
                            <input v-model="portalForm.policy_number" placeholder="Policy # (Optional)" class="rounded-lg border border-slate-300 px-2.5 py-2 font-mono" />
                            <input v-model="portalForm.claim_number" placeholder="Claim # (Optional)" class="rounded-lg border border-slate-300 px-2.5 py-2 font-mono" />
                        </div>
                    </div>
                    <textarea v-model="portalForm.instructions" rows="2" placeholder="Instructions shown on the branded portal page..." class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <label class="inline-flex items-center gap-2 font-semibold text-slate-700">
                            <input v-model="portalForm.require_otp" type="checkbox" class="rounded" />
                            Require 6-Digit Security PIN
                        </label>
                        <button type="submit" :disabled="portalForm.processing" class="rounded-lg bg-sky-700 px-4 py-2 font-semibold text-white hover:bg-sky-800">
                            Generate Magic Link
                        </button>
                    </div>
                </form>

                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50 text-left font-semibold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Request / Recipient</th>
                                <th class="px-4 py-3">Mode &amp; Refs</th>
                                <th class="px-4 py-3">Progress</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="r in portalRequests" :key="r.id" class="hover:bg-slate-50">
                                <td class="px-4 py-2.5">
                                    <p class="font-bold text-slate-900">{{ r.title }}</p>
                                    <p class="text-[11px] text-slate-500">{{ r.recipient_name }} · {{ r.recipient_phone || r.recipient_email || 'Direct link' }}</p>
                                </td>
                                <td class="px-4 py-2.5 font-mono text-[11px]">
                                    <span class="uppercase">{{ r.request_type }}</span>
                                    <span v-if="r.otp_protected" class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-900">PIN</span>
                                    <p class="text-slate-500">{{ r.policy_number || '' }} {{ r.claim_number || '' }}</p>
                                </td>
                                <td class="px-4 py-2.5 text-[11px]">
                                    <p>Uploads: {{ r.uploads_count }}/{{ r.max_uploads }}</p>
                                    <p v-if="r.signed_at" class="font-semibold text-emerald-700">Signed by {{ r.signed_by_name }}</p>
                                </td>
                                <td class="px-4 py-2.5">
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold uppercase">{{ r.status }}</span>
                                </td>
                                <td class="px-4 py-2.5">
                                    <button
                                        v-if="r.status === 'active'"
                                        type="button"
                                        @click="revokePortal(r.id)"
                                        class="inline-flex items-center gap-1 rounded bg-rose-50 px-2 py-1 font-semibold text-rose-700 hover:bg-rose-100"
                                    >
                                        <XCircle class="h-3.5 w-3.5" /> Revoke
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB 2: WHATSAPP BUSINESS INTAKE & ALERTS -->
            <div v-if="activeTab === 'whatsapp'" class="space-y-6">
                <div class="rounded-xl border border-emerald-200 bg-emerald-50/70 p-4 text-xs">
                    <p class="font-bold text-emerald-950">Meta WhatsApp Business Cloud API Webhook Endpoint</p>
                    <p class="mt-1 font-mono text-[11px] text-emerald-900">{{ webhookUrl }}</p>
                    <p v-if="issuedWaVerifyToken" class="mt-2 font-mono font-bold text-amber-900">
                        Verify Token (save in Meta Developer Console): {{ issuedWaVerifyToken }}
                    </p>
                </div>

                <form @submit.prevent="submitWaChannel" class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs space-y-3">
                    <h2 class="text-sm font-bold text-slate-900">Connect WhatsApp Business Number</h2>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <input v-model="waChannelForm.display_name" required placeholder="Channel Name" class="rounded-lg border border-slate-300 px-3 py-2" />
                        <input v-model="waChannelForm.phone_number_id" required placeholder="Meta Phone Number ID" class="rounded-lg border border-slate-300 px-3 py-2 font-mono" />
                        <input v-model="waChannelForm.app_secret" type="password" placeholder="Meta App Secret (HMAC Verify)" class="rounded-lg border border-slate-300 px-3 py-2 font-mono" />
                        <input v-model="waChannelForm.access_token" type="password" placeholder="Meta Permanent Access Token" class="rounded-lg border border-slate-300 px-3 py-2 font-mono" />
                        <select v-model="waChannelForm.default_cabinet_id" class="rounded-lg border border-slate-300 px-3 py-2">
                            <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <select v-model="waChannelForm.default_classification_level_id" class="rounded-lg border border-slate-300 px-3 py-2">
                            <option v-for="l in allowedClearances" :key="l.id" :value="l.id">{{ l.name }}</option>
                        </select>
                    </div>
                    <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">
                        Save WhatsApp Channel
                    </button>
                </form>

                <!-- Dispatch Executive WhatsApp Alert -->
                <form v-if="waChannels.length > 0" @submit.prevent="submitWaAlert" class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs space-y-3">
                    <h2 class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <Send class="h-4 w-4 text-emerald-700" /> Send WhatsApp Approval / Portal Alert
                    </h2>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                        <select v-model="waAlertForm.channel_id" class="rounded-lg border border-slate-300 px-3 py-2">
                            <option v-for="c in waChannels" :key="c.id" :value="c.id">{{ c.display_name }}</option>
                        </select>
                        <input v-model="waAlertForm.recipient_phone" required placeholder="+263771234567" class="rounded-lg border border-slate-300 px-3 py-2 font-mono" />
                        <input v-model="waAlertForm.subject" required placeholder="Invoice #401 awaiting approval" class="rounded-lg border border-slate-300 px-3 py-2" />
                        <input v-model="waAlertForm.action_url" required placeholder="https://.../workflows/inbox" class="rounded-lg border border-slate-300 px-3 py-2" />
                    </div>
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-800">
                        Send WhatsApp Alert
                    </button>
                </form>

                <!-- WhatsApp Message Ledger -->
                <div class="rounded-xl border border-slate-200 bg-white p-4 text-xs shadow-xs">
                    <h3 class="mb-3 font-bold uppercase tracking-wider text-slate-700">Recent WhatsApp Intake &amp; Alerts</h3>
                    <div v-if="waMessages.length === 0" class="flex items-center gap-2 text-slate-500">
                        <ShieldAlert class="h-4 w-4" /> No WhatsApp messages received or dispatched yet.
                    </div>
                    <div v-else class="space-y-2">
                        <div v-for="m in waMessages" :key="m.id" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-200 p-3">
                            <div>
                                <p class="font-bold text-slate-900">
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] uppercase">{{ m.direction }}</span>
                                    {{ m.sender_name || m.from_phone }} ({{ m.from_phone }})
                                </p>
                                <p class="mt-0.5 text-slate-600">{{ m.body_text || `[${m.message_type}]` }}</p>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold uppercase text-emerald-800">{{ m.status }}</span>
                                <Link v-if="m.document_id" :href="`/documents/${m.document_id}`" class="font-mono font-semibold text-sky-700 hover:underline">
                                    {{ m.document_number }}
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
