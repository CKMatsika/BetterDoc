<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CheckCircle2, Lock, ShieldAlert, ShieldCheck } from '@lucide/vue';

const props = defineProps<{
    token: string;
    envelope: {
        status: string;
        mode: string;
        completed_at: string | null;
        title: string;
    };
    document: {
        classification_rank: number;
        doc_number: string;
        title: string;
        metadata_hidden: boolean;
    };
    verification: {
        overall_status: string;
        signatures_count: number;
        verified_at: string | null;
        details: Record<string, unknown>;
    };
    signers: {
        sign_order: number;
        signer: string;
        tier: string;
        status: string;
        cert_serial: string | null;
        tsa_token_serial: string | null;
        signed_at: string | null;
        sha256: string | null;
    }[];
}>();

const isValid = props.verification.overall_status === 'valid';
</script>

<template>
    <Head title="BetterDoc Cryptographic Seal Verification" />
    <div class="min-h-screen bg-slate-950 px-4 py-10 text-slate-100">
        <div class="mx-auto max-w-3xl space-y-6">
            <div class="flex items-center justify-between border-b border-slate-800 pb-4">
                <div>
                    <p class="font-mono text-xs uppercase tracking-widest text-sky-400">BetterDoc DMS OFFLINE VERIFIER</p>
                    <h1 class="mt-1 text-2xl font-bold text-white">{{ document.doc_number }} — {{ document.title }}</h1>
                </div>
                <Link href="/login" class="rounded-lg border border-slate-700 px-3 py-1.5 text-xs font-semibold text-slate-200 hover:bg-slate-800">
                    Sign In to Vault
                </Link>
            </div>

            <div
                class="rounded-xl border-2 p-5"
                :class="isValid ? 'border-emerald-500 bg-emerald-950/60' : 'border-red-500 bg-red-950/60'"
            >
                <div class="flex items-center gap-3">
                    <ShieldCheck v-if="isValid" class="h-8 w-8 text-emerald-400" />
                    <ShieldAlert v-else class="h-8 w-8 text-red-400" />
                    <div>
                        <p class="text-lg font-bold uppercase tracking-wide">
                            Verification Status: {{ verification.overall_status }}
                        </p>
                        <p class="text-xs text-slate-300">
                            Token: <span class="font-mono">{{ token }}</span> · Envelope Status: {{ envelope.status }} ({{ verification.signatures_count }} cryptographic fields)
                        </p>
                    </div>
                </div>
            </div>

            <div v-if="document.metadata_hidden" class="flex items-center gap-2 rounded-lg border border-amber-500/40 bg-amber-950/50 p-3 text-xs text-amber-200">
                <Lock class="h-4 w-4 shrink-0" />
                <span>Document content &amp; title are redacted on the unauthenticated verifier because classification exceeds Rank 0.</span>
            </div>

            <div class="rounded-xl border border-slate-800 bg-slate-900 p-5 space-y-3">
                <h2 class="text-xs font-bold uppercase tracking-wider text-slate-400">Attested Signers &amp; RFC 3161 Timestamps</h2>
                <div v-for="s in signers" :key="s.sign_order" class="flex flex-wrap items-center justify-between gap-2 rounded-lg border border-slate-800 bg-slate-950/60 p-3 text-xs">
                    <div class="flex items-center gap-2">
                        <CheckCircle2 class="h-4 w-4 text-emerald-400" />
                        <div>
                            <p class="font-semibold text-white">#{{ s.sign_order }} {{ s.signer }}</p>
                            <p class="font-mono text-[11px] text-slate-400">
                                Tier: {{ s.tier }} · Cert: {{ s.cert_serial ?? 'Audit Stamp' }} · TSA: {{ s.tsa_token_serial ?? '-' }}
                            </p>
                        </div>
                    </div>
                    <div class="text-right font-mono text-[11px] text-slate-400">
                        <p>{{ s.status.toUpperCase() }}</p>
                        <p v-if="s.sha256">SHA-256: {{ s.sha256.slice(0, 16) }}...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
