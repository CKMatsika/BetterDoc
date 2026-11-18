<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    BookOpenCheck,
    CalendarClock,
    CheckCircle2,
    FileCheck2,
    RefreshCw,
    Search,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface ObligationRow {
    id: string;
    title: string;
    due_date: string;
    status: string;
    owner_name: string | null;
}

interface ContractRow {
    id: string;
    contract_number: string;
    title: string;
    counterparty_name: string;
    vendor_name: string | null;
    document_id: string;
    doc_number: string;
    effective_date: string;
    expiry_date: string;
    renewal_notice_days: number;
    value_cents: number;
    currency: string;
    status: string;
    auto_renew: boolean;
    obligations: ObligationRow[];
}

interface ClauseRow {
    id: string;
    contract_id: string | null;
    title: string;
    category: string;
    clause_text: string;
    is_standard_template: boolean;
}

const props = defineProps<{
    contracts: ContractRow[];
    clauses: ClauseRow[];
    clearedDocuments: { id: string; doc_number: string; title: string }[];
    vendors: { id: string; name: string }[];
    clauseQuery: string;
}>();

const searchTerm = ref(props.clauseQuery);
const newObligationMap = ref<Record<string, { title: string; due_date: string }>>({});

const contractForm = useForm({
    document_id: props.clearedDocuments[0]?.id ?? '',
    vendor_id: '',
    contract_number: '',
    title: '',
    counterparty_name: '',
    effective_date: new Date().toISOString().slice(0, 10),
    expiry_date: new Date(Date.now() + 90 * 86400 * 1000).toISOString().slice(0, 10),
    renewal_notice_days: 30,
    value_cents: 0,
    currency: 'USD',
    auto_renew: false,
});

const clauseForm = useForm({
    contract_id: '',
    title: '',
    category: 'compliance',
    clause_text: '',
    is_standard_template: true,
});

function createContract(): void {
    contractForm.post('/contracts', {
        preserveScroll: true,
        onSuccess: () => contractForm.reset('contract_number', 'title', 'counterparty_name'),
    });
}

function createClause(): void {
    clauseForm.post('/contracts/clauses', {
        preserveScroll: true,
        onSuccess: () => clauseForm.reset('title', 'clause_text'),
    });
}

function searchClauses(): void {
    router.get('/contracts', { q: searchTerm.value }, { preserveState: true, preserveScroll: true });
}

function addObligation(contractId: string): void {
    const entry = newObligationMap.value[contractId];
    if (!entry?.title || !entry?.due_date) {
        return;
    }
    router.post(`/contracts/${contractId}/obligations`, entry, {
        preserveScroll: true,
        onSuccess: () => {
            newObligationMap.value[contractId] = { title: '', due_date: '' };
        },
    });
}

function completeObligation(obId: string): void {
    router.post(`/contracts/obligations/${obId}/complete`, {}, { preserveScroll: true });
}

function scanAlerts(): void {
    router.post('/contracts/scan-alerts', {}, { preserveScroll: true });
}

function statusClass(status: string): string {
    switch (status) {
        case 'active':
        case 'fulfilled':
            return 'bg-emerald-100 text-emerald-800';
        case 'expiring_soon':
            return 'bg-amber-100 text-amber-800';
        case 'expired':
        case 'overdue':
        case 'terminated':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-slate-100 text-slate-700';
    }
}
</script>

<template>
    <Head title="Contract Lifecycle & Obligations" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Contract Lifecycle, Obligations & Clause Library</h1>
                    <p class="text-xs text-slate-600">
                        Automated expiry countdown alerts, compliance obligations tracking, and searchable standard clauses.
                    </p>
                </div>
                <button
                    type="button"
                    @click="scanAlerts"
                    class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                >
                    <RefreshCw class="h-3.5 w-3.5" /> Scan Expiry & Obligation Alerts
                </button>
            </div>

            <!-- Register Contract Form -->
            <form @submit.prevent="createContract" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                    <FileCheck2 class="h-4 w-4 text-sky-600" />
                    Register Executed Contract
                </div>
                <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600">Vault Document</label>
                        <select v-model="contractForm.document_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                            <option v-for="d in clearedDocuments" :key="d.id" :value="d.id">{{ d.doc_number }} — {{ d.title }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600">Contract Number</label>
                        <input v-model="contractForm.contract_number" required placeholder="CNT-2026-099" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600">Title</label>
                        <input v-model="contractForm.title" required placeholder="Cloud Escrow SLA" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600">Counterparty Name</label>
                        <input v-model="contractForm.counterparty_name" required placeholder="Acme Systems Pvt Ltd" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600">Effective Date</label>
                        <input v-model="contractForm.effective_date" type="date" required class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600">Expiry Date</label>
                        <input v-model="contractForm.expiry_date" type="date" required class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600">Renewal Notice Window (Days)</label>
                        <input v-model.number="contractForm.renewal_notice_days" type="number" min="1" max="365" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    </div>
                    <div class="flex items-end gap-2">
                        <label class="flex items-center gap-1.5 text-xs text-slate-700 pb-2">
                            <input v-model="contractForm.auto_renew" type="checkbox" class="rounded border-slate-300" />
                            Auto-Renew
                        </label>
                    </div>
                </div>
                <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                    Register Contract
                </button>
            </form>

            <!-- Contracts & Obligations -->
            <div class="space-y-4">
                <div v-for="c in contracts" :key="c.id" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-sm font-bold text-slate-900">{{ c.contract_number }}</span>
                                <span class="text-sm font-semibold text-slate-800">{{ c.title }}</span>
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="statusClass(c.status)">
                                    {{ c.status }}
                                </span>
                            </div>
                            <div class="mt-1 text-xs text-slate-600">
                                Counterparty: <strong>{{ c.counterparty_name }}</strong> ·
                                Doc: <Link :href="`/documents/${c.document_id}`" class="text-sky-700 hover:underline">{{ c.doc_number }}</Link> ·
                                Effective {{ c.effective_date }} → Expires <strong>{{ c.expiry_date }}</strong> (Notice: {{ c.renewal_notice_days }}d)
                            </div>
                        </div>
                        <div class="font-mono text-xs font-bold text-slate-700">
                            {{ c.currency }} {{ (c.value_cents / 100).toFixed(2) }}
                        </div>
                    </div>

                    <!-- Obligations -->
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-2">
                        <div class="flex items-center gap-1.5 text-xs font-semibold text-slate-700">
                            <CalendarClock class="h-3.5 w-3.5 text-slate-500" />
                            Contractual Obligations
                        </div>
                        <div v-for="ob in c.obligations" :key="ob.id" class="flex flex-wrap items-center justify-between gap-2 rounded bg-white px-3 py-2 text-xs border border-slate-200">
                            <div>
                                <span class="font-semibold text-slate-800">{{ ob.title }}</span>
                                <span class="ml-2 font-mono text-[11px] text-slate-500">Due: {{ ob.due_date }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="statusClass(ob.status)">
                                    <CheckCircle2 v-if="ob.status === 'fulfilled'" class="mr-0.5 inline h-3 w-3" />
                                    <AlertTriangle v-if="ob.status === 'overdue'" class="mr-0.5 inline h-3 w-3" />
                                    {{ ob.status }}
                                </span>
                                <button
                                    v-if="ob.status !== 'fulfilled'"
                                    type="button"
                                    @click="completeObligation(ob.id)"
                                    class="rounded bg-emerald-700 px-2.5 py-1 text-[11px] font-semibold text-white hover:bg-emerald-800"
                                >
                                    Mark Fulfilled
                                </button>
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2 pt-1">
                            <input
                                :value="newObligationMap[c.id]?.title ?? ''"
                                @input="(e) => { newObligationMap[c.id] = { ...(newObligationMap[c.id] ?? { due_date: '' }), title: (e.target as HTMLInputElement).value }; }"
                                placeholder="New obligation milestone..."
                                class="flex-1 rounded border border-slate-300 bg-white px-2.5 py-1 text-xs"
                            />
                            <input
                                type="date"
                                :value="newObligationMap[c.id]?.due_date ?? ''"
                                @input="(e) => { newObligationMap[c.id] = { ...(newObligationMap[c.id] ?? { title: '' }), due_date: (e.target as HTMLInputElement).value }; }"
                                class="rounded border border-slate-300 bg-white px-2.5 py-1 text-xs"
                            />
                            <button
                                type="button"
                                @click="addObligation(c.id)"
                                class="rounded bg-slate-800 px-3 py-1 text-xs font-semibold text-white hover:bg-slate-900"
                            >
                                Add Obligation
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Standard Clause Library -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <BookOpenCheck class="h-4 w-4 text-sky-600" />
                        Standard Clause Library
                    </div>
                    <form @submit.prevent="searchClauses" class="flex gap-2">
                        <input
                            v-model="searchTerm"
                            type="text"
                            placeholder="Search indemnity, termination, cyber..."
                            class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs"
                        />
                        <button type="submit" class="inline-flex items-center gap-1 rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white">
                            <Search class="h-3.5 w-3.5" /> Search
                        </button>
                    </form>
                </div>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div v-for="cl in clauses" :key="cl.id" class="rounded-lg border border-slate-200 bg-slate-50 p-3 space-y-1">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-slate-900">{{ cl.title }}</span>
                            <span class="rounded bg-sky-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-sky-800">{{ cl.category }}</span>
                        </div>
                        <p class="text-xs text-slate-700 leading-relaxed">{{ cl.clause_text }}</p>
                    </div>
                </div>

                <form @submit.prevent="createClause" class="grid grid-cols-1 gap-2 border-t border-slate-200 pt-4 md:grid-cols-4">
                    <input v-model="clauseForm.title" placeholder="Clause Title" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    <input v-model="clauseForm.category" placeholder="Category (e.g. liability)" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    <input v-model="clauseForm.clause_text" placeholder="Full clause wording..." required class="md:col-span-2 rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    <div>
                        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-1.5 text-xs font-semibold text-white hover:bg-slate-800">
                            Add Clause to Library
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
