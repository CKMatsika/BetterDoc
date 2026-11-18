<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Database,
    GitBranch,
    HardDrive,
    RefreshCw,
    RotateCcw,
    ShieldAlert,
    ShieldCheck,
    WifiOff,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{
    syncTargets: {
        id: string;
        name: string;
        driver: string;
        endpoint_url: string | null;
        bucket_or_site: string;
        max_classification_rank: number;
        conflict_strategy: string;
        enabled: boolean;
        last_synced_at: string | null;
    }[];
    outboxEvents: {
        id: string;
        target_name: string;
        doc_number: string;
        classification_rank: number;
        status: string;
        conflict_branch_label: string | null;
        conflict_details: string | null;
        attempts: number;
        synced_at: string | null;
    }[];
    backupSnapshots: {
        id: string;
        snapshot_label: string;
        backup_tier: string;
        size_bytes: number;
        manifest_sha256: string;
        status: string;
        creator_name: string | null;
        verified_at: string | null;
        last_restored_at: string | null;
        restore_duration_ms: number | null;
        created_at: string | null;
    }[];
    clearedDocuments: { id: string; doc_number: string; title: string; classification_rank: number }[];
    airGapped: boolean;
    syncEnabled: boolean;
    targetRtoSeconds: number;
    canManageOperations: boolean;
}>();

const targetForm = useForm({
    name: '',
    driver: 'local_mirror',
    endpoint_url: '',
    bucket_or_site: 'dr-site-harare',
    max_classification_rank: 1,
    conflict_strategy: 'branch_conflict',
});

const enqueueForm = useForm({
    document_id: props.clearedDocuments[0]?.id ?? '',
    sync_target_id: props.syncTargets[0]?.id ?? '',
});

const backupForm = useForm({
    backup_tier: 'tier1_local',
    snapshot_label: '',
});

function createTarget(): void {
    targetForm.post('/admin/operations/sync-targets', {
        preserveScroll: true,
        onSuccess: () => targetForm.reset('name', 'endpoint_url'),
    });
}

function enqueueSync(): void {
    enqueueForm.post('/admin/operations/sync-enqueue', { preserveScroll: true });
}

function processOutbox(): void {
    router.post('/admin/operations/sync-process', {}, { preserveScroll: true });
}

function createBackup(): void {
    backupForm.post('/admin/operations/backups', {
        preserveScroll: true,
        onSuccess: () => backupForm.reset('snapshot_label'),
    });
}

function runRestoreDrill(snapshotId: string): void {
    router.post(`/admin/operations/backups/${snapshotId}/restore-drill`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Sync Gateway, 3-2-1 Backups & DR" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Site Sync Gateway, 3-2-1 Backups & Disaster Recovery</h1>
                    <p class="text-xs text-slate-600">
                        Outbox encrypted replication with classification ceiling, XChaCha20-Poly1305 3-2-1 snapshots, and &lt;{{ Math.round(targetRtoSeconds / 3600) }}h clean-machine restore verification.
                    </p>
                </div>
                <span
                    class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold"
                    :class="airGapped ? 'bg-slate-900 text-white' : 'bg-emerald-100 text-emerald-900'"
                >
                    <WifiOff v-if="airGapped" class="h-3.5 w-3.5" />
                    <ShieldCheck v-else class="h-3.5 w-3.5" />
                    {{ airGapped ? 'AIR-GAPPED (SYNC HARD-BLOCKED)' : syncEnabled ? 'LAN + OUTBOX SYNC ACTIVE' : 'LAN ONLY (SYNC DISABLED)' }}
                </span>
            </div>

            <!-- 3-2-1 ENCRYPTED BACKUP & DR RESTORE DRILL -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <HardDrive class="h-4 w-4 text-sky-600" />
                        3-2-1 Encrypted Backups & Timed Restore Drill (RTO &lt; 2h)
                    </div>
                    <form v-if="canManageOperations" @submit.prevent="createBackup" class="flex flex-wrap items-center gap-2">
                        <select v-model="backupForm.backup_tier" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs font-semibold">
                            <option value="tier1_local">Tier 1 — Local NVMe Vault + WAL</option>
                            <option value="tier2_offsite_nas">Tier 2 — Off-Site NAS Replica</option>
                            <option value="tier3_immutable_usb">Tier 3 — Offline Immutable USB</option>
                        </select>
                        <input
                            v-model="backupForm.snapshot_label"
                            type="text"
                            placeholder="Snapshot label..."
                            class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs"
                        />
                        <button
                            type="submit"
                            :disabled="backupForm.processing"
                            class="rounded-lg bg-slate-900 px-3.5 py-1.5 text-xs font-semibold text-white hover:bg-slate-800"
                        >
                            Create Encrypted Snapshot
                        </button>
                    </form>
                </div>

                <div class="overflow-hidden rounded-lg border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50 text-left font-semibold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-3 py-2">Snapshot</th>
                                <th class="px-3 py-2">Tier</th>
                                <th class="px-3 py-2">Size</th>
                                <th class="px-3 py-2">Manifest SHA-256</th>
                                <th class="px-3 py-2">DR Drill Status</th>
                                <th class="px-3 py-2">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="snap in backupSnapshots" :key="snap.id">
                                <td class="px-3 py-2.5 font-semibold text-slate-900">{{ snap.snapshot_label }}</td>
                                <td class="px-3 py-2.5 font-mono text-slate-600">{{ snap.backup_tier }}</td>
                                <td class="px-3 py-2.5 font-mono">{{ (snap.size_bytes / 1024).toFixed(1) }} KB</td>
                                <td class="px-3 py-2.5 font-mono text-slate-500">{{ snap.manifest_sha256.slice(0, 16) }}...</td>
                                <td class="px-3 py-2.5">
                                    <span
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                        :class="snap.status === 'failed' ? 'bg-red-100 text-red-800' : 'bg-emerald-100 text-emerald-800'"
                                    >
                                        <CheckCircle2 v-if="snap.status !== 'failed'" class="h-3 w-3" />
                                        <ShieldAlert v-else class="h-3 w-3" />
                                        {{ snap.status }}
                                    </span>
                                    <span v-if="snap.restore_duration_ms !== null" class="ml-1.5 font-mono text-[11px] text-slate-600">
                                        ({{ snap.restore_duration_ms }}ms)
                                    </span>
                                </td>
                                <td class="px-3 py-2.5">
                                    <button
                                        v-if="canManageOperations"
                                        type="button"
                                        @click="runRestoreDrill(snap.id)"
                                        class="inline-flex items-center gap-1 rounded bg-sky-700 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-sky-800"
                                    >
                                        <RotateCcw class="h-3 w-3" /> Run Timed Restore Drill
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- OPTIONAL SYNC GATEWAY -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <Database class="h-4 w-4 text-sky-600" />
                        Optional Site Sync Gateway & Classification Ceiling
                    </div>
                    <button
                        v-if="canManageOperations"
                        type="button"
                        @click="processOutbox"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3 py-1.5 text-xs font-semibold text-slate-800 hover:bg-slate-50"
                    >
                        <RefreshCw class="h-3.5 w-3.5" /> Dispatch Outbox Queue
                    </button>
                </div>

                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2" v-if="canManageOperations">
                    <form @submit.prevent="createTarget" class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                        <p class="text-xs font-bold text-slate-800">Register Sync Target</p>
                        <div class="grid grid-cols-2 gap-2">
                            <input v-model="targetForm.name" placeholder="Target Name" required class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs" />
                            <select v-model="targetForm.driver" class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                                <option value="local_mirror">Local DR Mirror</option>
                                <option value="s3_compatible">S3-Compatible Object Store</option>
                                <option value="laravel_cloud">Laravel Cloud Replica</option>
                                <option value="secondary_site">Secondary BetterDoc Site</option>
                            </select>
                            <input v-model="targetForm.bucket_or_site" placeholder="Bucket / Site Slug" required class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs" />
                            <select v-model.number="targetForm.max_classification_rank" class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                                <option :value="0">Ceiling: L0 Public Only</option>
                                <option :value="1">Ceiling: Up to L1 Internal</option>
                                <option :value="2">Ceiling: Up to L2 Confidential</option>
                            </select>
                        </div>
                        <button type="submit" class="rounded bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white">Save Sync Target</button>
                    </form>

                    <form @submit.prevent="enqueueSync" class="rounded-lg border border-slate-200 bg-slate-50 p-4 space-y-3">
                        <p class="text-xs font-bold text-slate-800">Queue Encrypted Document Sync (Respects Air-Gap & Ceiling)</p>
                        <div class="grid grid-cols-1 gap-2">
                            <select v-model="enqueueForm.document_id" required class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                                <option v-for="doc in clearedDocuments" :key="doc.id" :value="doc.id">
                                    L{{ doc.classification_rank }} · {{ doc.doc_number }} — {{ doc.title }}
                                </option>
                            </select>
                            <select v-model="enqueueForm.sync_target_id" required class="rounded border border-slate-300 bg-white px-2.5 py-1.5 text-xs">
                                <option v-for="t in syncTargets" :key="t.id" :value="t.id">
                                    {{ t.name }} ({{ t.driver }} · Max L{{ t.max_classification_rank }})
                                </option>
                            </select>
                        </div>
                        <button type="submit" class="rounded bg-sky-700 px-3 py-1.5 text-xs font-semibold text-white">Enqueue Sync Envelope</button>
                    </form>
                </div>

                <!-- Outbox Events -->
                <div v-if="outboxEvents.length > 0" class="overflow-hidden rounded-lg border border-slate-200">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50 text-left font-semibold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-3 py-2">Document</th>
                                <th class="px-3 py-2">Target</th>
                                <th class="px-3 py-2">Status</th>
                                <th class="px-3 py-2">Conflict Branch / Details</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="ev in outboxEvents" :key="ev.id">
                                <td class="px-3 py-2 font-mono font-semibold">{{ ev.doc_number }} (L{{ ev.classification_rank }})</td>
                                <td class="px-3 py-2">{{ ev.target_name }}</td>
                                <td class="px-3 py-2 font-mono font-semibold">{{ ev.status }}</td>
                                <td class="px-3 py-2 text-xs text-slate-600">
                                    <span v-if="ev.conflict_branch_label" class="inline-flex items-center gap-1 font-mono text-amber-800">
                                        <GitBranch class="h-3 w-3" /> {{ ev.conflict_branch_label }}
                                    </span>
                                    <span v-else>{{ ev.conflict_details ?? 'Clean encrypted outbox record' }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
