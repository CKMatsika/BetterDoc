<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Award, Download, KeyRound, ShieldAlert, ShieldCheck } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref } from 'vue';

const props = defineProps<{
    tenantId: string;
    authorities: {
        id: string;
        name: string;
        role: string;
        serial_number: string;
        subject_dn: string;
        fingerprint_sha256: string;
        crl_number: number;
        valid_from: string;
        valid_to: string;
    }[];
    certificates: {
        id: string;
        user_id: string;
        user_name: string;
        user_email: string;
        serial_number: string;
        subject_dn: string;
        fingerprint_sha256: string;
        custody_mode: string;
        pkcs11_key_label: string;
        status: string;
        valid_from: string;
        valid_to: string;
        revoked_at: string | null;
        revocation_reason: string | null;
    }[];
    tenantUsers: { id: string; name: string; email: string; rank_title: string | null }[];
    canManageAll: boolean;
    urls: {
        root_ca: string;
        crl: string;
    };
}>();

const issueForm = useForm({
    user_id: props.tenantUsers[0]?.id ?? '',
    custody_mode: 'hsm_managed',
    valid_days: 365,
    csr_pem: '',
    pkcs11_slot_id: 'slot-0',
});

const revokeId = ref<string | null>(null);
const revokeForm = useForm({
    reason: 'key_compromise',
});

function bootstrapCa(): void {
    router.post('/pki/bootstrap');
}

function submitIssue(): void {
    issueForm.post('/pki/certificates', {
        preserveScroll: true,
        onSuccess: () => {
            issueForm.reset('csr_pem');
        },
    });
}

function submitRevoke(certId: string): void {
    revokeForm.post(`/pki/certificates/${certId}/revoke`, {
        preserveScroll: true,
        onSuccess: () => {
            revokeId.value = null;
        },
    });
}
</script>

<template>
    <Head title="Internal PKI & Certificates" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-slate-900">Internal PKI, HSM &amp; Signing Certificates</h1>
                    <p class="mt-1 text-sm text-slate-600">
                        Offline Root CA, Intermediate Issuing CA, RFC 3161 TSA, and CRL/OCSP responders — zero internet trust dependencies.
                    </p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <a v-if="authorities.length" :href="urls.root_ca" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50">
                        <Download class="h-3.5 w-3.5" /> Root CA (.crt)
                    </a>
                    <a v-if="authorities.length" :href="urls.crl" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50">
                        <Download class="h-3.5 w-3.5" /> Live CRL (.der)
                    </a>
                    <button v-if="canManageAll && authorities.length === 0" type="button" @click="bootstrapCa" class="inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-3.5 py-2 text-xs font-semibold text-white hover:bg-sky-500">
                        <Award class="h-4 w-4" /> Bootstrap Tenant CA
                    </button>
                </div>
            </div>

            <!-- CA Hierarchy Cards -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <div v-for="ca in authorities" :key="ca.id" class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                    <div class="flex items-center justify-between">
                        <span class="rounded bg-slate-900 px-2 py-0.5 font-mono text-[10px] font-bold uppercase text-white">{{ ca.role }}</span>
                        <span class="font-mono text-[11px] text-slate-500">CRL #{{ ca.crl_number }}</span>
                    </div>
                    <p class="mt-2 text-sm font-bold text-slate-900">{{ ca.name }}</p>
                    <p class="mt-1 font-mono text-[11px] text-slate-600">Serial: {{ ca.serial_number }}</p>
                    <p class="truncate font-mono text-[10px] text-slate-400">SHA-256: {{ ca.fingerprint_sha256 }}</p>
                </div>
            </div>

            <!-- Issue User X.509 Certificate -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <div class="mb-3 flex items-center gap-2 text-sm font-bold text-slate-900">
                    <KeyRound class="h-4 w-4 text-sky-600" />
                    <span>Issue X.509 PAdES User Certificate</span>
                </div>
                <form @submit.prevent="submitIssue" class="space-y-3">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                        <div v-if="canManageAll && tenantUsers.length">
                            <label class="mb-1 block text-xs font-semibold text-slate-700">Officer / Personnel</label>
                            <select v-model="issueForm.user_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <option v-for="u in tenantUsers" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">Key Custody Mode</label>
                            <select v-model="issueForm.custody_mode" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs">
                                <option value="hsm_managed">Server HSM Custody (Sealed Non-DB Key)</option>
                                <option value="smartcard_pkcs11">Hardware Smartcard / USB PKCS#11 (CSR)</option>
                                <option value="webauthn_bound">WebAuthn Step-Up HSM Bound</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-semibold text-slate-700">Validity (Days)</label>
                            <input v-model.number="issueForm.valid_days" type="number" min="1" max="1825" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs" />
                        </div>
                    </div>

                    <div v-if="issueForm.custody_mode === 'smartcard_pkcs11'">
                        <label class="mb-1 block text-xs font-semibold text-slate-700">PKCS#10 CSR PEM (Generated on Hardware Token)</label>
                        <textarea v-model="issueForm.csr_pem" rows="3" placeholder="-----BEGIN CERTIFICATE REQUEST-----" class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-xs" />
                        <p v-if="issueForm.errors.csr_pem" class="mt-1 text-xs font-semibold text-red-600">{{ issueForm.errors.csr_pem }}</p>
                    </div>

                    <button type="submit" :disabled="issueForm.processing" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                        Issue Signed Certificate
                    </button>
                </form>
            </div>

            <!-- Issued Certificates Table -->
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                <table class="min-w-full divide-y divide-slate-200 text-xs">
                    <thead class="bg-slate-50 text-left font-bold uppercase tracking-wider text-slate-600">
                        <tr>
                            <th class="px-4 py-3">Subject</th>
                            <th class="px-4 py-3">Serial / Slot</th>
                            <th class="px-4 py-3">Custody</th>
                            <th class="px-4 py-3">Status</th>
                            <th class="px-4 py-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="cert in certificates" :key="cert.id">
                            <td class="px-4 py-3">
                                <p class="font-semibold text-slate-900">{{ cert.user_name }}</p>
                                <p class="font-mono text-[11px] text-slate-500">{{ cert.subject_dn }}</p>
                            </td>
                            <td class="px-4 py-3 font-mono text-[11px]">
                                <p>{{ cert.serial_number }}</p>
                                <p class="text-slate-400">{{ cert.pkcs11_key_label }}</p>
                            </td>
                            <td class="px-4 py-3">
                                <span class="rounded bg-sky-100 px-2 py-0.5 font-mono text-[11px] font-semibold text-sky-900">{{ cert.custody_mode }}</span>
                            </td>
                            <td class="px-4 py-3">
                                <span
                                    class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold uppercase"
                                    :class="cert.status === 'active' ? 'bg-emerald-100 text-emerald-900' : 'bg-red-100 text-red-900'"
                                >
                                    <ShieldCheck v-if="cert.status === 'active'" class="h-3 w-3" />
                                    <ShieldAlert v-else class="h-3 w-3" />
                                    {{ cert.status }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <button
                                    v-if="cert.status === 'active'"
                                    type="button"
                                    @click="revokeId = revokeId === cert.id ? null : cert.id"
                                    class="rounded border border-red-200 px-2.5 py-1 font-semibold text-red-700 hover:bg-red-50"
                                >
                                    Revoke
                                </button>
                                <div v-if="revokeId === cert.id" class="mt-2 flex items-center gap-2">
                                    <select v-model="revokeForm.reason" class="rounded border border-slate-300 px-2 py-1 text-[11px]">
                                        <option value="key_compromise">keyCompromise</option>
                                        <option value="cessation_of_operation">cessationOfOperation</option>
                                        <option value="privilege_withdrawn">privilegeWithdrawn</option>
                                    </select>
                                    <button type="button" @click="submitRevoke(cert.id)" class="rounded bg-red-700 px-2.5 py-1 text-[11px] font-semibold text-white">
                                        Confirm
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
