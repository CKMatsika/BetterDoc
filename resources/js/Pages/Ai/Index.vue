<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { AlertOctagon, Bot, Check, FileSearch, Lock, Send, ShieldAlert, Sparkles, X } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface ChatMessageItem {
    id: string;
    role: 'user' | 'assistant' | 'system';
    content: string;
    high_water_rank: number;
    cited_chunks: {
        document_id: string;
        doc_number: string;
        title: string;
        classification_rank: number;
        classification_name: string;
        score: number;
        excerpt: string;
    }[];
    model_used: string;
    latency_ms: number;
}

interface ChatSessionItem {
    id: string;
    title: string;
    high_water_rank: number;
    high_water_compartments: string[];
    model_name: string;
    messages: ChatMessageItem[];
}

interface IdpItem {
    id: string;
    document_id: string;
    doc_number: string;
    document_title: string;
    detected_doc_type: string;
    overall_confidence: number;
    review_status: string;
    model_name: string;
    fields: Record<string, { label: string; value: string; confidence: number }>;
    reviewer_name: string | null;
}

interface AnomalyItem {
    id: string;
    alert_type: string;
    severity: string;
    status: string;
    title: string;
    actor_name: string | null;
    document_id: string | null;
    details: Record<string, unknown>;
    created_at: string | null;
}

const props = defineProps<{
    sessions: ChatSessionItem[];
    idpQueue: IdpItem[];
    anomalyAlerts: AnomalyItem[];
    aiAuditLogs: {
        id: string;
        action: string;
        model_used: string;
        user_name: string | null;
        high_water_rank: number;
        retrieved_count: number;
        latency_ms: number;
        prompt_sha256: string;
        created_at: string | null;
    }[];
    clearedDocuments: { id: string; doc_number: string; title: string; classification_rank: number }[];
    cabinets: { id: string; name: string }[];
    allowedClearances: { id: string; rank: number; code: string; name: string }[];
    aiStatus: {
        driver: string;
        chat_model: string;
        embed_model: string;
        cloud_ai_blocked: boolean;
    };
    canManageAnomalies: boolean;
}>();

const activeTab = ref<'rag' | 'idp' | 'anomalies' | 'audit'>('rag');
const selectedSessionId = ref<string>(props.sessions[0]?.id ?? '');
const activeSession = computed(() => props.sessions.find((s) => s.id === selectedSessionId.value) ?? props.sessions[0] ?? null);

const chatForm = useForm({
    query: '',
    session_id: '' as string | null,
    focus_document_id: '' as string | null,
});

const exportForm = useForm({
    cabinet_id: props.cabinets[0]?.id ?? '',
    target_classification_level_id: props.allowedClearances[0]?.id ?? '',
    title: 'AI Brief Synthesis',
});

const idpRunDocId = ref(props.clearedDocuments[0]?.id ?? '');
const editedFields = ref<Record<string, Record<string, string>>>({});

function sendChat(): void {
    chatForm.session_id = activeSession.value?.id ?? null;
    chatForm.post('/ai/chat', {
        preserveScroll: true,
        onSuccess: () => {
            chatForm.reset('query');
        },
    });
}

function startNewSession(): void {
    selectedSessionId.value = '__new__';
    chatForm.session_id = null;
}

function exportBrief(): void {
    if (!activeSession.value) {
        return;
    }
    exportForm.post(`/ai/sessions/${activeSession.value.id}/export-draft`);
}

function triggerIdp(): void {
    if (!idpRunDocId.value) {
        return;
    }
    router.post(`/documents/${idpRunDocId.value}/ai/idp`, {}, { preserveScroll: true });
}

function getFieldVal(ex: IdpItem, key: string): string {
    return editedFields.value[ex.id]?.[key] ?? ex.fields[key]?.value ?? '';
}

function setFieldVal(exId: string, key: string, val: string): void {
    if (!editedFields.value[exId]) {
        editedFields.value[exId] = {};
    }
    editedFields.value[exId][key] = val;
}

function submitIdpReview(ex: IdpItem, status: 'approved' | 'corrected' | 'rejected'): void {
    router.post(
        `/ai/idp/${ex.id}/review`,
        {
            review_status: status,
            corrected_values: editedFields.value[ex.id] ?? {},
        },
        { preserveScroll: true }
    );
}

function triggerAnomalyScan(): void {
    router.post('/ai/anomalies/scan', {}, { preserveScroll: true });
}

function setAnomalyStatus(alertId: string, status: 'acknowledged' | 'dismissed' | 'escalated'): void {
    router.post(`/ai/anomalies/${alertId}/status`, { status }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Local AI Assistant, IDP & Anomaly Guard" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Local-First AI Workspace (IDP · Pre-Filtered RAG · Anomaly Guard)</h1>
                    <p class="text-xs text-slate-600">
                        Engine: <span class="font-mono font-semibold">{{ aiStatus.driver }}</span> · Models: <span class="font-mono">{{ aiStatus.chat_model }}</span> + <span class="font-mono">{{ aiStatus.embed_model }}</span> ·
                        <span class="font-semibold text-emerald-700">{{ aiStatus.cloud_ai_blocked ? 'Cloud AI Hard-Blocked (Air-Gapped)' : 'LAN Local First' }}</span>
                    </p>
                </div>

                <div class="flex flex-wrap gap-1.5 rounded-lg border border-slate-200 bg-white p-1 text-xs">
                    <button
                        type="button"
                        @click="activeTab = 'rag'"
                        class="rounded-md px-3 py-1.5 font-semibold"
                        :class="activeTab === 'rag' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                    >
                        Hybrid RAG Assistant
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'idp'"
                        class="rounded-md px-3 py-1.5 font-semibold"
                        :class="activeTab === 'idp' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                    >
                        IDP Review Queue ({{ idpQueue.length }})
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'anomalies'"
                        class="rounded-md px-3 py-1.5 font-semibold"
                        :class="activeTab === 'anomalies' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                    >
                        Anomaly Alerts ({{ anomalyAlerts.length }})
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'audit'"
                        class="rounded-md px-3 py-1.5 font-semibold"
                        :class="activeTab === 'audit' ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                    >
                        AI Provenance Log
                    </button>
                </div>
            </div>

            <!-- TAB 1: Permission-Safe Hybrid RAG Chat -->
            <div v-if="activeTab === 'rag'" class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <div class="space-y-4 rounded-xl border border-slate-200 bg-white p-4 shadow-xs text-xs">
                    <div class="flex items-center justify-between">
                        <h2 class="font-bold uppercase tracking-wider text-slate-600">Cleared Chat Sessions</h2>
                        <button type="button" @click="startNewSession" class="rounded bg-sky-600 px-2.5 py-1 font-semibold text-white hover:bg-sky-500">
                            New Chat
                        </button>
                    </div>
                    <div class="space-y-2">
                        <button
                            v-for="s in sessions"
                            :key="s.id"
                            type="button"
                            @click="selectedSessionId = s.id"
                            class="block w-full rounded-lg border p-2.5 text-left transition"
                            :class="activeSession?.id === s.id ? 'border-sky-500 bg-sky-50' : 'border-slate-200 hover:bg-slate-50'"
                        >
                            <div class="truncate font-semibold text-slate-900">{{ s.title }}</div>
                            <div class="mt-1 flex items-center gap-1.5 font-mono text-[10px]">
                                <span class="rounded bg-amber-100 px-1.5 py-0.5 font-bold text-amber-900">
                                    HIGH-WATER L{{ s.high_water_rank }}
                                </span>
                                <span v-for="c in s.high_water_compartments" :key="c" class="rounded bg-slate-800 px-1 py-0.5 text-white">{{ c }}</span>
                            </div>
                        </button>
                    </div>

                    <!-- Export Brief with High-Water-Mark Write-Down Guard -->
                    <div v-if="activeSession" class="border-t border-slate-200 pt-3 space-y-2">
                        <h3 class="flex items-center gap-1 font-bold text-slate-700">
                            <Lock class="h-3.5 w-3.5 text-amber-600" />
                            <span>Save AI Brief (Min Rank L{{ activeSession.high_water_rank }})</span>
                        </h3>
                        <form @submit.prevent="exportBrief" class="space-y-2">
                            <input v-model="exportForm.title" type="text" required class="w-full rounded border border-slate-300 px-2 py-1.5" />
                            <select v-model="exportForm.cabinet_id" class="w-full rounded border border-slate-300 px-2 py-1.5">
                                <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <select v-model="exportForm.target_classification_level_id" class="w-full rounded border border-slate-300 px-2 py-1.5">
                                <option v-for="lvl in allowedClearances" :key="lvl.id" :value="lvl.id">L{{ lvl.rank }} — {{ lvl.name }}</option>
                            </select>
                            <p v-if="exportForm.errors.target_classification_level_id" class="text-[11px] font-semibold text-red-600">
                                {{ exportForm.errors.target_classification_level_id }}
                            </p>
                            <button type="submit" :disabled="exportForm.processing" class="w-full rounded bg-slate-900 px-3 py-1.5 font-semibold text-white">
                                Export Session to Vault
                            </button>
                        </form>
                    </div>
                </div>

                <div class="flex flex-col justify-between rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-2">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 pb-3 text-xs">
                        <div class="flex items-center gap-2 font-bold text-slate-900">
                            <Bot class="h-4 w-4 text-sky-600" />
                            <span>Pre-Filtered Security-Safe Assistant (PostgreSQL RLS + Hybrid Retrieval)</span>
                        </div>
                        <span v-if="activeSession" class="rounded bg-amber-100 px-2 py-0.5 font-mono text-[10px] font-bold text-amber-950">
                            SESSION CEILING: L{{ activeSession.high_water_rank }}
                        </span>
                    </div>

                    <div class="mb-4 max-h-96 space-y-3 overflow-y-auto text-xs">
                        <div v-if="!activeSession || activeSession.messages.length === 0" class="py-10 text-center text-slate-500">
                            Ask any question across documents within your clearance and need-to-know compartments.
                        </div>
                        <div
                            v-for="msg in activeSession?.messages ?? []"
                            :key="msg.id"
                            class="rounded-lg p-3"
                            :class="msg.role === 'user' ? 'ml-8 bg-slate-900 text-white' : 'mr-8 border border-slate-200 bg-slate-50 text-slate-900'"
                        >
                            <div class="mb-1 flex items-center justify-between text-[10px] opacity-75">
                                <span class="font-bold uppercase">{{ msg.role }}</span>
                                <span v-if="msg.role === 'assistant'" class="font-mono">High-Water L{{ msg.high_water_rank }} · {{ msg.model_used }} · {{ msg.latency_ms }}ms</span>
                            </div>
                            <p class="whitespace-pre-line">{{ msg.content }}</p>

                            <div v-if="msg.cited_chunks.length > 0" class="mt-2 flex flex-wrap gap-1.5 border-t border-slate-200 pt-2">
                                <Link
                                    v-for="(cite, idx) in msg.cited_chunks"
                                    :key="idx"
                                    :href="`/documents/${cite.document_id}`"
                                    class="rounded bg-sky-100 px-2 py-0.5 font-mono text-[10px] font-semibold text-sky-900 hover:underline"
                                >
                                    {{ cite.doc_number }} (L{{ cite.classification_rank }} · {{ Math.round(cite.score * 100) }}%)
                                </Link>
                            </div>
                        </div>
                    </div>

                    <form @submit.prevent="sendChat" class="space-y-2 border-t border-slate-200 pt-3 text-xs">
                        <div class="flex flex-wrap gap-2">
                            <select v-model="chatForm.focus_document_id" class="w-56 rounded-lg border border-slate-300 px-2.5 py-2">
                                <option value="">Search all cleared vault docs</option>
                                <option v-for="doc in clearedDocuments" :key="doc.id" :value="doc.id">{{ doc.doc_number }} — {{ doc.title }}</option>
                            </select>
                            <input
                                v-model="chatForm.query"
                                type="text"
                                required
                                placeholder="Ask a question across your cleared documents..."
                                class="flex-1 rounded-lg border border-slate-300 px-3 py-2 focus:border-sky-500 focus:outline-none"
                            />
                            <button type="submit" :disabled="chatForm.processing" class="inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-4 py-2 font-semibold text-white hover:bg-sky-500">
                                <Send class="h-3.5 w-3.5" /> Ask
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- TAB 2: IDP Human-in-the-Loop Review Queue -->
            <div v-if="activeTab === 'idp'" class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white p-4 shadow-xs text-xs">
                    <div class="flex items-center gap-2 font-bold text-slate-900">
                        <FileSearch class="h-4 w-4 text-sky-600" />
                        <span>Run Zero-Shot Field Extraction on Cleared Document</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <select v-model="idpRunDocId" class="w-72 rounded-lg border border-slate-300 px-3 py-1.5">
                            <option v-for="doc in clearedDocuments" :key="doc.id" :value="doc.id">{{ doc.doc_number }} — {{ doc.title }}</option>
                        </select>
                        <button type="button" @click="triggerIdp" class="rounded-lg bg-slate-900 px-3 py-1.5 font-semibold text-white hover:bg-slate-800">
                            Extract Fields (IDP)
                        </button>
                    </div>
                </div>

                <div v-if="idpQueue.length === 0" class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
                    No IDP extractions queued yet.
                </div>

                <div v-else class="space-y-4">
                    <div v-for="ex in idpQueue" :key="ex.id" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs text-xs space-y-3">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <Link :href="`/documents/${ex.document_id}`" class="font-mono font-bold text-sky-700 hover:underline">{{ ex.doc_number }}</Link>
                                <span class="ml-2 font-semibold text-slate-900">{{ ex.document_title }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="rounded bg-indigo-100 px-2 py-0.5 font-mono text-[10px] font-bold uppercase text-indigo-900">{{ ex.detected_doc_type }}</span>
                                <span class="rounded bg-emerald-100 px-2 py-0.5 font-mono text-[10px] font-bold text-emerald-900">
                                    Confidence: {{ Math.round(ex.overall_confidence * 100) }}%
                                </span>
                                <span class="rounded bg-slate-100 px-2 py-0.5 font-mono text-[10px] font-bold uppercase">{{ ex.review_status }}</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div v-for="(item, key) in ex.fields" :key="key" class="rounded-lg border border-slate-200 bg-slate-50 p-2.5">
                                <div class="mb-1 flex items-center justify-between">
                                    <span class="font-semibold text-slate-700">{{ item.label }}</span>
                                    <span class="font-mono text-[10px] text-slate-500">{{ Math.round(item.confidence * 100) }}%</span>
                                </div>
                                <input
                                    :value="getFieldVal(ex, String(key))"
                                    @input="setFieldVal(ex.id, String(key), ($event.target as HTMLInputElement).value)"
                                    type="text"
                                    class="w-full rounded border border-slate-300 bg-white px-2 py-1 font-mono"
                                />
                            </div>
                        </div>

                        <div v-if="ex.review_status === 'pending_review'" class="flex flex-wrap gap-2">
                            <button type="button" @click="submitIdpReview(ex, 'approved')" class="inline-flex items-center gap-1 rounded-lg bg-emerald-600 px-3 py-1.5 font-semibold text-white hover:bg-emerald-500">
                                <Check class="h-3.5 w-3.5" /> Approve &amp; Promote Metadata
                            </button>
                            <button type="button" @click="submitIdpReview(ex, 'corrected')" class="inline-flex items-center gap-1 rounded-lg bg-sky-600 px-3 py-1.5 font-semibold text-white hover:bg-sky-500">
                                <Sparkles class="h-3.5 w-3.5" /> Save Corrections
                            </button>
                            <button type="button" @click="submitIdpReview(ex, 'rejected')" class="inline-flex items-center gap-1 rounded-lg border border-red-200 bg-red-50 px-3 py-1.5 font-semibold text-red-700">
                                <X class="h-3.5 w-3.5" /> Reject
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: Security & Invoice Anomaly Alerts -->
            <div v-if="activeTab === 'anomalies'" class="space-y-4">
                <div class="flex items-center justify-between rounded-xl border border-slate-200 bg-white p-4 shadow-xs text-xs">
                    <div class="flex items-center gap-2 font-bold text-slate-900">
                        <ShieldAlert class="h-4 w-4 text-red-600" />
                        <span>Behavioral &amp; Duplicate Invoice Anomaly Detector</span>
                    </div>
                    <button
                        v-if="canManageAnomalies"
                        type="button"
                        @click="triggerAnomalyScan"
                        class="rounded-lg bg-red-700 px-3 py-1.5 font-semibold text-white hover:bg-red-600"
                    >
                        Run Anomaly Sweep Now
                    </button>
                </div>

                <div v-if="anomalyAlerts.length === 0" class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-500">
                    No anomalous bulk downloads, after-hours Secret+ accesses, or duplicate invoices detected.
                </div>
                <div v-else class="space-y-3">
                    <div v-for="alert in anomalyAlerts" :key="alert.id" class="flex flex-wrap items-center justify-between gap-4 rounded-xl border border-red-200 bg-white p-4 text-xs shadow-xs">
                        <div class="space-y-1">
                            <div class="flex items-center gap-2">
                                <AlertOctagon class="h-4 w-4 text-red-600" />
                                <span class="rounded bg-red-700 px-2 py-0.5 font-mono text-[10px] font-bold text-white uppercase">{{ alert.severity }}</span>
                                <span class="font-bold text-slate-900">{{ alert.title }}</span>
                                <span class="rounded bg-slate-100 px-2 py-0.5 font-mono text-[10px] uppercase">{{ alert.status }}</span>
                            </div>
                            <p class="font-mono text-[11px] text-slate-500">{{ JSON.stringify(alert.details) }}</p>
                        </div>
                        <div v-if="canManageAnomalies && alert.status === 'open'" class="flex gap-2">
                            <button type="button" @click="setAnomalyStatus(alert.id, 'acknowledged')" class="rounded bg-amber-600 px-2.5 py-1 font-semibold text-white">
                                Acknowledge
                            </button>
                            <button type="button" @click="setAnomalyStatus(alert.id, 'dismissed')" class="rounded border border-slate-300 px-2.5 py-1 font-semibold text-slate-700">
                                Dismiss
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 4: AI Provenance & Audit Chain -->
            <div v-if="activeTab === 'audit'" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <h2 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-700">AI Provenance Ledger (Zero Customer Training)</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-slate-200 text-[11px] uppercase text-slate-500">
                            <tr>
                                <th class="py-2 pr-3">Action</th>
                                <th class="py-2 pr-3">User</th>
                                <th class="py-2 pr-3">Model</th>
                                <th class="py-2 pr-3">High-Water</th>
                                <th class="py-2 pr-3">Cited Docs</th>
                                <th class="py-2 pr-3">Latency</th>
                                <th class="py-2">Prompt SHA-256</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-mono text-[11px]">
                            <tr v-for="log in aiAuditLogs" :key="log.id">
                                <td class="py-2 pr-3 font-semibold text-slate-900">{{ log.action }}</td>
                                <td class="py-2 pr-3 font-sans">{{ log.user_name }}</td>
                                <td class="py-2 pr-3">{{ log.model_used }}</td>
                                <td class="py-2 pr-3">L{{ log.high_water_rank }}</td>
                                <td class="py-2 pr-3">{{ log.retrieved_count }}</td>
                                <td class="py-2 pr-3">{{ log.latency_ms }}ms</td>
                                <td class="py-2 text-slate-500">{{ log.prompt_sha256.slice(0, 14) }}...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
