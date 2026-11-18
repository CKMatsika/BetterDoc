<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { Folder, Plus, Search, Lock, Upload, Bookmark, Tag as TagIcon } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClassificationBadge from '@/Components/ClassificationBadge.vue';
import ResumableUploader from '@/Components/ResumableUploader.vue';
import type { SharedProps } from '@/types/betterdoc';

interface CabinetRow {
    id: string;
    name: string;
    default_classification_rank: number;
    document_count: number;
}

interface DocRow {
    id: string;
    doc_number: string;
    title: string;
    snippet?: string;
    status: string;
    classification_rank: number;
    classification: { id: string; code: string; name: string; color_hex: string };
    cabinet_name: string | null;
    org_unit_code: string | null;
    owner_name: string | null;
    compartments: string[];
    tags: { id: string; name: string; color_hex: string }[];
    current_version: { id: string; label: string; mime_type: string; page_count: number } | null;
    locked_by_name: string | null;
}

const props = defineProps<{
    cabinets: CabinetRow[];
    documents: DocRow[];
    searchMeta: { total: number; facets: Record<string, number>; elapsed_ms: number; engine: string };
    allowedClearances: { id: string; rank: number; code: string; name: string; color_hex: string }[];
    userCompartments: { id: string; code: string; name: string }[];
    tags: { id: string; name: string; color_hex: string }[];
    smartViews: { id: string; name: string; filter_json: { cabinet_id?: string; tag_id?: string; q?: string }; is_shared: boolean }[];
    filters: { cabinet_id: string | null; tag_id: string | null; q: string };
}>();

const page = usePage<{ props: SharedProps }>();
const shared = computed(() => page.props as unknown as SharedProps);

const search = ref(props.filters.q ?? '');
const suggestions = ref<{ id: string; doc_number: string; title: string; classification_rank: number }[]>([]);
const showCreateModal = ref(false);
const showUploadModal = ref(false);

const smartViewForm = useForm({
    name: '',
    filter_json: {
        cabinet_id: null as string | null,
        tag_id: null as string | null,
        q: '',
    },
    is_shared: false,
});

const createForm = useForm({
    cabinet_id: props.cabinets[0]?.id ?? '',
    classification_level_id: props.allowedClearances[0]?.id ?? '',
    doc_number: '',
    title: '',
    summary: '',
    compartment_ids: [] as string[],
    unit_wide_readable: true,
});

function runSearch(): void {
    suggestions.value = [];
    router.get('/documents', {
        cabinet_id: props.filters.cabinet_id,
        tag_id: props.filters.tag_id,
        q: search.value,
    }, { preserveState: true });
}

async function fetchAutocomplete(): Promise<void> {
    if (search.value.trim().length < 2) {
        suggestions.value = [];
        return;
    }
    const res = await fetch(`/search/autocomplete?q=${encodeURIComponent(search.value.trim())}`, { credentials: 'same-origin' });
    if (res.ok) {
        const data = (await res.json()) as { suggestions: typeof suggestions.value };
        suggestions.value = data.suggestions;
    }
}

function selectCabinet(cabinetId: string | null): void {
    router.get('/documents', { cabinet_id: cabinetId, tag_id: props.filters.tag_id, q: search.value }, { preserveState: true });
}

function selectTag(tagId: string | null): void {
    router.get('/documents', { cabinet_id: props.filters.cabinet_id, tag_id: tagId, q: search.value }, { preserveState: true });
}

function applySmartView(view: { filter_json: { cabinet_id?: string; tag_id?: string; q?: string } }): void {
    search.value = view.filter_json.q ?? '';
    router.get('/documents', view.filter_json, { preserveState: true });
}

function saveSmartView(): void {
    const name = window.prompt('Smart View Name:');
    if (!name) {
        return;
    }
    smartViewForm.name = name;
    smartViewForm.filter_json = {
        cabinet_id: props.filters.cabinet_id,
        tag_id: props.filters.tag_id,
        q: search.value,
    };
    smartViewForm.post('/smart-views', { preserveScroll: true });
}

function submitCreate(): void {
    createForm.post('/documents', {
        onSuccess: () => {
            showCreateModal.value = false;
            createForm.reset('doc_number', 'title', 'summary', 'compartment_ids');
        },
    });
}

function onUploadCompleted(payload: { document_id: string }): void {
    showUploadModal.value = false;
    router.visit(`/documents/${payload.document_id}`);
}

function splitHighlight(snippet?: string): { text: string; bold: boolean }[] {
    if (!snippet) {
        return [];
    }
    return snippet.split(/(<b>.*?<\/b>)/g).filter(Boolean).map((part) => {
        if (part.startsWith('<b>') && part.endsWith('</b>')) {
            return { text: part.slice(3, -4), bold: true };
        }
        return { text: part, bold: false };
    });
}
</script>

<template>
    <Head title="Document Vault" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">
                        {{ shared.tenant?.terminology?.document ?? 'Document' }} Vault
                    </h1>
                    <p class="text-xs text-slate-600">
                        {{ searchMeta.total }} cleared records · {{ searchMeta.elapsed_ms }}ms ({{ searchMeta.engine }})
                    </p>
                </div>
                <div v-if="cabinets.length > 0" class="flex flex-wrap gap-2">
                    <button
                        type="button"
                        @click="showUploadModal = true"
                        class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-500"
                    >
                        <Upload class="h-4 w-4" />
                        <span>Upload File</span>
                    </button>
                    <button
                        type="button"
                        @click="showCreateModal = true"
                        class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                    >
                        <Plus class="h-4 w-4" />
                        <span>New Record</span>
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-4">
                <!-- Left Sidebar: Cabinets, Smart Views & Tags -->
                <div class="space-y-4">
                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                        <h2 class="mb-3 text-xs font-bold uppercase tracking-wider text-slate-500">
                            Cleared {{ shared.tenant?.terminology?.cabinet ?? 'Cabinets' }}
                        </h2>
                        <div class="space-y-1">
                            <button
                                type="button"
                                @click="selectCabinet(null)"
                                class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-xs font-medium"
                                :class="!filters.cabinet_id ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                            >
                                <span>All Cleared Records</span>
                                <span class="font-mono">{{ searchMeta.total }}</span>
                            </button>
                            <button
                                v-for="cab in cabinets"
                                :key="cab.id"
                                type="button"
                                @click="selectCabinet(cab.id)"
                                class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-xs font-medium"
                                :class="filters.cabinet_id === cab.id ? 'bg-slate-900 text-white' : 'text-slate-700 hover:bg-slate-100'"
                            >
                                <span class="flex items-center gap-2 truncate">
                                    <Folder class="h-3.5 w-3.5 shrink-0" />
                                    <span class="truncate">{{ cab.name }}</span>
                                </span>
                                <span class="font-mono">{{ cab.document_count }}</span>
                            </button>
                        </div>
                    </div>

                    <div class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                        <div class="mb-2 flex items-center justify-between">
                            <h2 class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-500">
                                <Bookmark class="h-3.5 w-3.5" /> Smart Views
                            </h2>
                            <button type="button" @click="saveSmartView" class="text-[11px] font-semibold text-sky-600 hover:underline">
                                + Save Current
                            </button>
                        </div>
                        <p v-if="smartViews.length === 0" class="text-xs text-slate-400">No saved smart views yet.</p>
                        <div v-else class="space-y-1">
                            <button
                                v-for="view in smartViews"
                                :key="view.id"
                                type="button"
                                @click="applySmartView(view)"
                                class="block w-full rounded-lg px-2.5 py-1.5 text-left text-xs font-medium text-slate-700 hover:bg-slate-100"
                            >
                                {{ view.name }}
                            </button>
                        </div>
                    </div>

                    <div v-if="tags.length > 0" class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                        <h2 class="mb-2 flex items-center gap-1.5 text-xs font-bold uppercase tracking-wider text-slate-500">
                            <TagIcon class="h-3.5 w-3.5" /> Tags
                        </h2>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                type="button"
                                @click="selectTag(null)"
                                class="rounded px-2 py-0.5 text-[11px] font-medium"
                                :class="!filters.tag_id ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700'"
                            >
                                All
                            </button>
                            <button
                                v-for="t in tags"
                                :key="t.id"
                                type="button"
                                @click="selectTag(t.id)"
                                class="rounded px-2 py-0.5 text-[11px] font-medium"
                                :class="filters.tag_id === t.id ? 'bg-sky-600 text-white' : 'bg-slate-100 text-slate-700'"
                            >
                                {{ t.name }}
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Search & Documents Table -->
                <div class="space-y-4 lg:col-span-3">
                    <form @submit.prevent="runSearch" class="relative flex gap-2">
                        <div class="relative flex-1">
                            <Search class="absolute top-2.5 left-3 h-4 w-4 text-slate-400" />
                            <input
                                v-model="search"
                                @input="fetchAutocomplete"
                                type="text"
                                placeholder="Full-text + OCR search across cleared documents..."
                                class="w-full rounded-lg border border-slate-300 bg-white py-2 pr-3 pl-9 text-sm focus:border-sky-500 focus:outline-none"
                            />
                            <div v-if="suggestions.length > 0" class="absolute right-0 left-0 z-20 mt-1 rounded-lg border border-slate-200 bg-white shadow-lg">
                                <Link
                                    v-for="s in suggestions"
                                    :key="s.id"
                                    :href="`/documents/${s.id}`"
                                    class="flex items-center justify-between px-3 py-2 text-xs hover:bg-slate-50"
                                >
                                    <span class="font-semibold text-slate-900">{{ s.title }}</span>
                                    <span class="font-mono text-slate-500">{{ s.doc_number }} · L{{ s.classification_rank }}</span>
                                </Link>
                            </div>
                        </div>
                        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800">
                            Search
                        </button>
                    </form>

                    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wider text-slate-500">
                                <tr>
                                    <th class="px-4 py-3">Document</th>
                                    <th class="px-4 py-3">Classification</th>
                                    <th class="px-4 py-3">Version / Lock</th>
                                    <th class="px-4 py-3">Unit / Owner</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-if="documents.length === 0">
                                    <td colspan="4" class="px-4 py-8 text-center text-sm text-slate-500">
                                        No cleared documents match your query. Restricted records are hidden by MAC &amp; PostgreSQL RLS.
                                    </td>
                                </tr>
                                <tr v-for="doc in documents" :key="doc.id" class="hover:bg-slate-50">
                                    <td class="px-4 py-3">
                                        <Link :href="`/documents/${doc.id}`" class="font-semibold text-slate-900 hover:text-sky-600">
                                            {{ doc.title }}
                                        </Link>
                                        <div class="font-mono text-xs text-slate-500">{{ doc.doc_number }} · {{ doc.cabinet_name }}</div>
                                        <p v-if="doc.snippet" class="mt-1 text-xs text-slate-600">
                                            <template v-for="(part, idx) in splitHighlight(doc.snippet)" :key="idx">
                                                <mark v-if="part.bold" class="rounded bg-amber-200 px-0.5 font-semibold text-slate-900">{{ part.text }}</mark>
                                                <span v-else>{{ part.text }}</span>
                                            </template>
                                        </p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-col items-start gap-1">
                                            <ClassificationBadge
                                                :name="doc.classification.name"
                                                :color-hex="doc.classification.color_hex"
                                                :rank="doc.classification_rank"
                                            />
                                            <div class="flex flex-wrap gap-1">
                                                <span v-for="c in doc.compartments" :key="c" class="inline-flex items-center gap-0.5 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px]">
                                                    <Lock class="h-2.5 w-2.5" /> {{ c }}
                                                </span>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 font-mono text-xs text-slate-700">
                                        <div v-if="doc.current_version">{{ doc.current_version.label }} ({{ doc.current_version.page_count }}p)</div>
                                        <div v-else class="text-slate-400">Metadata only</div>
                                        <div v-if="doc.locked_by_name" class="mt-0.5 text-[11px] font-semibold text-amber-700">
                                            Checked out: {{ doc.locked_by_name }}
                                        </div>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-600">
                                        <div class="font-medium">{{ doc.org_unit_code }}</div>
                                        <div>{{ doc.owner_name }}</div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Resumable Upload Modal -->
        <div v-if="showUploadModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-2xl">
                <ResumableUploader
                    :cabinets="cabinets"
                    :allowed-clearances="allowedClearances"
                    :user-compartments="userCompartments"
                    @completed="onUploadCompleted"
                />
                <div class="mt-2 text-right">
                    <button type="button" @click="showUploadModal = false" class="rounded-lg bg-white px-4 py-1.5 text-xs font-semibold text-slate-800 shadow">
                        Close
                    </button>
                </div>
            </div>
        </div>

        <!-- Metadata Record Modal -->
        <div v-if="showCreateModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                <h2 class="mb-4 text-lg font-bold text-slate-900">Register New Classified Record</h2>
                <form @submit.prevent="submitCreate" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">Cabinet</label>
                            <select v-model="createForm.cabinet_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">Classification</label>
                            <select v-model="createForm.classification_level_id" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                                <option v-for="lvl in allowedClearances" :key="lvl.id" :value="lvl.id">L{{ lvl.rank }} — {{ lvl.name }}</option>
                            </select>
                        </div>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">Reference Number</label>
                        <input v-model="createForm.doc_number" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">Title</label>
                        <input v-model="createForm.title" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-700">Summary</label>
                        <textarea v-model="createForm.summary" rows="3" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm" />
                    </div>
                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="showCreateModal = false" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700">Cancel</button>
                        <button type="submit" :disabled="createForm.processing" class="rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-500">Save Record</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
