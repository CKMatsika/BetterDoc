<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { CheckCircle2, ShieldAlert, Lock } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

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
    checkpoints: {
        id: string;
        first_seq_id: number;
        last_seq_id: number;
        event_count: number;
        head_hash: string;
        created_at: string;
    }[];
}>();

function createCheckpoint(): void {
    router.post('/audit/checkpoints');
}
</script>

<template>
    <Head title="Immutable Audit Ledger" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Tamper-Evident Audit Ledger</h1>
                    <p class="text-xs text-slate-600">Append-only PostgreSQL partitions · UPDATE/DELETE forbidden · SHA-256 hash chain.</p>
                </div>
                <button
                    type="button"
                    @click="createCheckpoint"
                    class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                >
                    Anchor Signed Checkpoint
                </button>
            </div>

            <!-- Hash Chain Status Banner -->
            <div
                class="flex items-center justify-between rounded-xl border p-4"
                :class="verification.valid ? 'border-emerald-300 bg-emerald-50 text-emerald-950' : 'border-red-400 bg-red-50 text-red-950'"
            >
                <div class="flex items-center gap-3">
                    <CheckCircle2 v-if="verification.valid" class="h-6 w-6 text-emerald-600" />
                    <ShieldAlert v-else class="h-6 w-6 text-red-600" />
                    <div>
                        <p class="text-sm font-bold">
                            {{ verification.valid ? 'CRYPTOGRAPHIC HASH CHAIN VERIFIED INTACT' : 'CHAIN INTEGRITY FAILURE DETECTED' }}
                        </p>
                        <p class="font-mono text-xs">
                            Events Verified: {{ verification.total_events }} · Head: {{ verification.head_hash ?? 'GENESIS' }}
                        </p>
                    </div>
                </div>
            </div>

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
