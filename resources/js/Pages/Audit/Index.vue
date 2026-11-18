<script setup lang="ts">
import { ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Download, FileArchive, Lock, ShieldAlert, ShieldCheck, Terminal } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface PackVerifyResult {
    pack_id: string;
    manifest_valid: boolean;
    files_valid: boolean;
    chain_valid: boolean;
    checkpoints_valid: boolean;
    files: string[];
}

defineProps<{
    events: {
        seq_id: number;
        occurred_at: string;
        actor_ip: string | null;
        event_type: string;
        resource_type: string;
        resource_id: string | null;
        classification_rank: number;
        masked: boolean;
        after: Record<string, unknown> | null;
        entry_hash: string;
    }[];
    verification: {
        valid: boolean;
        total_events: number;
        broken_at_seq_id: number | null;
        head_hash: string | null;
        reason: string | null;
    };
    checkpointVerification: {
        valid: boolean;
        checked: number;
        failed_id: string | null;
        reason: string | null;
    };
    checkpoints: {
        id: string;
        first_seq_id: number;
        last_seq_id: number;
        event_count: number;
        head_hash: string;
        created_at: string;
    }[];
    compliancePacks: {
        id: string;
        pack_name: string;
        regulation_standard: string;
        event_count: number;
        head_hash: string;
        manifest_sha256: string;
        generator_name: string | null;
        created_at: string | null;
    }[];
    canExportCompliance: boolean;
}>();

const packForm = useForm({
    regulation_standard: 'ZIMRA',
    pack_name: '',
});

const verifyResults = ref<Record<string, PackVerifyResult>>({});

function createCheckpoint(): void {
    router.post('/audit/checkpoints', {}, { preserveScroll: true });
}

function generatePack(): void {
    packForm.post('/compliance/packs', {
        preserveScroll: true,
        onSuccess: () => packForm.reset('pack_name'),
    });
}

async function verifyPack(packId: string): Promise<void> {
    const res = await fetch(`/compliance/packs/${packId}/verify`, {
        headers: { Accept: 'application/json' },
    });
    if (res.ok) {
        verifyResults.value[packId] = (await res.json()) as PackVerifyResult;
    }
}
</script>

<template>
    <Head title="Immutable Audit Ledger" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Tamper-Evident Audit Ledger & Compliance Packs</h1>
                    <p class="text-xs text-slate-600">Append-only PostgreSQL partitions · HMAC-signed checkpoints · Regulator ZIP bundles & RFC 5424 Syslog.</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <a
                        v-if="canExportCompliance"
                        href="/compliance/syslog"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50"
                    >
                        <Terminal class="h-3.5 w-3.5" /> Export RFC 5424 Syslog
                    </a>
                    <button
                        type="button"
                        @click="createCheckpoint"
                        class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                    >
                        Anchor Signed Checkpoint
                    </button>
                </div>
            </div>

            <!-- Dual Cryptographic Verification Banners -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                <div
                    class="flex items-center gap-3 rounded-xl border p-4"
                    :class="verification.valid ? 'border-emerald-300 bg-emerald-50 text-emerald-950' : 'border-red-400 bg-red-50 text-red-950'"
                >
                    <CheckCircle2 v-if="verification.valid" class="h-6 w-6 shrink-0 text-emerald-600" />
                    <ShieldAlert v-else class="h-6 w-6 shrink-0 text-red-600" />
                    <div>
                        <p class="text-sm font-bold">
                            {{ verification.valid ? 'SHA-256 HASH CHAIN VERIFIED INTACT' : 'CHAIN INTEGRITY FAILURE DETECTED' }}
                        </p>
                        <p class="font-mono text-xs">
                            Events: {{ verification.total_events }} · Head: {{ verification.head_hash ? verification.head_hash.slice(0, 18) + '...' : 'GENESIS' }}
                        </p>
                    </div>
                </div>

                <div
                    class="flex items-center gap-3 rounded-xl border p-4"
                    :class="checkpointVerification.valid ? 'border-emerald-300 bg-emerald-50 text-emerald-950' : 'border-red-400 bg-red-50 text-red-950'"
                >
                    <ShieldCheck v-if="checkpointVerification.valid" class="h-6 w-6 shrink-0 text-emerald-600" />
                    <ShieldAlert v-else class="h-6 w-6 shrink-0 text-red-600" />
                    <div>
                        <p class="text-sm font-bold">
                            {{ checkpointVerification.valid ? 'CHECKPOINT HMAC SIGNATURES VERIFIED' : 'CHECKPOINT SIGNATURE MISMATCH' }}
                        </p>
                        <p class="font-mono text-xs">
                            Signed Checkpoints Verified: {{ checkpointVerification.checked }}
                            <span v-if="checkpointVerification.reason">· {{ checkpointVerification.reason }}</span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Regulator Export Pack Generator & Bundles -->
            <div v-if="canExportCompliance" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <FileArchive class="h-4 w-4 text-sky-600" />
                        Encrypted Regulator Export Packs
                    </div>
                    <form @submit.prevent="generatePack" class="flex flex-wrap items-center gap-2">
                        <select v-model="packForm.regulation_standard" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold">
                            <option value="ZIMRA">ZIMRA Tax & Fiscal</option>
                            <option value="RBZ_AML">RBZ AML / Financial</option>
                            <option value="ISO_27001">ISO/IEC 27001 Controls</option>
                            <option value="CYBER_DATA_ACT">Cyber & Data Protection Act</option>
                            <option value="ISO_15489">ISO 15489 Records Audit</option>
                        </select>
                        <input
                            v-model="packForm.pack_name"
                            type="text"
                            placeholder="Optional pack label..."
                            class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs"
                        />
                        <button
                            type="submit"
                            :disabled="packForm.processing"
                            class="rounded-lg bg-sky-700 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-sky-800"
                        >
                            Generate Signed ZIP Pack
                        </button>
                    </form>
                </div>

                <div v-if="compliancePacks.length > 0" class="overflow-hidden rounded-lg border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50 text-left font-semibold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-3 py-2">Pack Name</th>
                                <th class="px-3 py-2">Standard</th>
                                <th class="px-3 py-2">Events</th>
                                <th class="px-3 py-2">Manifest SHA-256</th>
                                <th class="px-3 py-2">Actions & Verification</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="pack in compliancePacks" :key="pack.id">
                                <td class="px-3 py-2 font-semibold text-slate-900">{{ pack.pack_name }}</td>
                                <td class="px-3 py-2 font-mono">{{ pack.regulation_standard }}</td>
                                <td class="px-3 py-2 font-mono">{{ pack.event_count }}</td>
                                <td class="px-3 py-2 font-mono text-slate-500">{{ pack.manifest_sha256.slice(0, 16) }}...</td>
                                <td class="px-3 py-2 space-y-1">
                                    <div class="flex flex-wrap gap-2">
                                        <button
                                            type="button"
                                            @click="verifyPack(pack.id)"
                                            class="rounded border border-slate-300 bg-white px-2.5 py-1 text-[11px] font-semibold text-slate-800 hover:bg-slate-50"
                                        >
                                            Verify Seals
                                        </button>
                                        <a
                                            :href="`/compliance/packs/${pack.id}/download`"
                                            class="inline-flex items-center gap-1 rounded bg-slate-900 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-slate-800"
                                        >
                                            <Download class="h-3 w-3" /> Download ZIP
                                        </a>
                                    </div>
                                    <div v-if="verifyResults[pack.id]" class="font-mono text-[11px]">
                                        <span
                                            :class="
                                                verifyResults[pack.id].manifest_valid &&
                                                verifyResults[pack.id].files_valid &&
                                                verifyResults[pack.id].chain_valid &&
                                                verifyResults[pack.id].checkpoints_valid
                                                    ? 'text-emerald-700 font-semibold'
                                                    : 'text-red-700 font-semibold'
                                            "
                                        >
                                            Manifest={{ verifyResults[pack.id].manifest_valid }} · Files={{ verifyResults[pack.id].files_valid }} ·
                                            Chain={{ verifyResults[pack.id].chain_valid }} · Checkpoints={{ verifyResults[pack.id].checkpoints_valid }}
                                        </span>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Checkpoints Anchor History -->
            <div v-if="checkpoints.length > 0" class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                <p class="mb-2 text-xs font-bold uppercase tracking-wider text-slate-600">Recent Anchored Checkpoints</p>
                <div class="grid grid-cols-1 gap-2 md:grid-cols-2">
                    <div v-for="cp in checkpoints" :key="cp.id" class="flex items-center justify-between rounded bg-slate-50 px-3 py-2 font-mono text-[11px]">
                        <span>Seq #{{ cp.first_seq_id }}..#{{ cp.last_seq_id }} ({{ cp.event_count }} ev)</span>
                        <span class="text-slate-500">{{ cp.head_hash.slice(0, 16) }}...</span>
                    </div>
                </div>
            </div>

            <!-- Audit Events Ledger -->
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                <table class="min-w-full divide-y divide-slate-200 text-xs">
                    <thead class="bg-slate-50 text-left font-semibold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Seq</th>
                            <th class="px-4 py-3">Timestamp (UTC)</th>
                            <th class="px-4 py-3">Event</th>
                            <th class="px-4 py-3">Resource</th>
                            <th class="px-4 py-3">Payload</th>
                            <th class="px-4 py-3">Entry SHA-256</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-mono">
                        <tr v-for="ev in events" :key="ev.seq_id" class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-semibold">#{{ ev.seq_id }}</td>
                            <td class="px-4 py-2.5 text-slate-600">{{ ev.occurred_at }}</td>
                            <td class="px-4 py-2.5 font-semibold text-sky-700">{{ ev.event_type }}</td>
                            <td class="px-4 py-2.5">{{ ev.resource_type }} (L{{ ev.classification_rank }})</td>
                            <td class="px-4 py-2.5">
                                <span v-if="ev.masked" class="inline-flex items-center gap-1 rounded bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-800">
                                    <Lock class="h-3 w-3" /> [REDACTED - RANK L{{ ev.classification_rank }}]
                                </span>
                                <span v-else class="text-slate-700">{{ JSON.stringify(ev.after) }}</span>
                            </td>
                            <td class="px-4 py-2.5 text-slate-500">{{ ev.entry_hash.slice(0, 16) }}...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
