<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import { FilePlus2, FileText } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface TemplateItem {
    id: string;
    code: string;
    name: string;
    category: string;
    body_html: string;
    placeholders: { key: string; label: string; required?: boolean }[];
    auto_number_prefix: string;
    next_sequence: number;
    default_classification_level_id: string | null;
}

const props = defineProps<{
    templates: TemplateItem[];
    allowedClearances: { id: string; rank: number; code: string; name: string; color_hex: string }[];
    cabinets: { id: string; name: string; default_classification_rank: number }[];
    userCompartments: { id: string; code: string; name: string }[];
    categories: string[];
}>();

const selectedTemplateId = ref(props.templates[0]?.id ?? '');
const showCreateTemplate = ref(false);

const activeTemplate = computed(() => props.templates.find((t) => t.id === selectedTemplateId.value) ?? null);

function buildInitialVars(tpl: TemplateItem | null): Record<string, string> {
    const out: Record<string, string> = {};
    if (!tpl) {
        return out;
    }
    for (const p of tpl.placeholders) {
        out[p.key] = '';
    }
    return out;
}

const generateForm = useForm({
    cabinet_id: props.cabinets[0]?.id ?? '',
    classification_level_id:
        activeTemplate.value?.default_classification_level_id ?? props.allowedClearances[0]?.id ?? '',
    title: '',
    compartment_ids: [] as string[],
    variables: buildInitialVars(activeTemplate.value),
});

watch(activeTemplate, (tpl) => {
    generateForm.variables = buildInitialVars(tpl);
    if (tpl?.default_classification_level_id) {
        generateForm.classification_level_id = tpl.default_classification_level_id;
    }
});

const newTemplateForm = useForm({
    code: 'CORP-LETTER-02',
    name: 'Official Dispatch Letter',
    category: props.categories[0] ?? 'letter',
    auto_number_prefix: 'SENT/DISPATCH',
    body_html: '<p><strong>TO:</strong> {{recipient_name}}</p><p><strong>SUBJECT:</strong> {{subject}}</p><p>{{body_text}}</p>',
    placeholders: [
        { key: 'recipient_name', label: 'Recipient Name', required: true },
        { key: 'subject', label: 'Subject Line', required: true },
        { key: 'body_text', label: 'Body Text', required: true },
    ],
    default_classification_level_id: props.allowedClearances[0]?.id ?? null,
});

function submitGenerate(): void {
    if (!activeTemplate.value) {
        return;
    }
    generateForm.post(`/templates/${activeTemplate.value.id}/generate`);
}

function submitNewTemplate(): void {
    newTemplateForm.post('/templates', {
        onSuccess: () => {
            showCreateTemplate.value = false;
        },
    });
}
</script>

<template>
    <Head title="Templates & Mail-Merge" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Document Templates &amp; Mail-Merge Generator</h1>
                    <p class="text-xs text-slate-600">Generate auto-numbered PDF/A memos, letters, minutes, and contracts offline using PyMuPDF.</p>
                </div>
                <button
                    type="button"
                    @click="showCreateTemplate = !showCreateTemplate"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                >
                    <FilePlus2 class="h-4 w-4" /> {{ showCreateTemplate ? 'Close Template Editor' : 'New Template' }}
                </button>
            </div>

            <div v-if="showCreateTemplate" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <h2 class="mb-3 text-sm font-bold text-slate-900">Create / Update Template</h2>
                <form @submit.prevent="submitNewTemplate" class="space-y-3 text-xs">
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-4">
                        <input v-model="newTemplateForm.code" type="text" required placeholder="Code (AFZ-MEMO-02)" class="rounded-lg border border-slate-300 px-3 py-2 font-mono" />
                        <input v-model="newTemplateForm.name" type="text" required placeholder="Template Name" class="rounded-lg border border-slate-300 px-3 py-2" />
                        <select v-model="newTemplateForm.category" class="rounded-lg border border-slate-300 px-3 py-2">
                            <option v-for="c in categories" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <input v-model="newTemplateForm.auto_number_prefix" type="text" required placeholder="Auto-Number Prefix" class="rounded-lg border border-slate-300 px-3 py-2 font-mono" />
                    </div>
                    <textarea v-model="newTemplateForm.body_html" rows="4" required class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono" />
                    <button type="submit" :disabled="newTemplateForm.processing" class="rounded-lg bg-sky-600 px-4 py-2 font-semibold text-white">
                        Save Template Schema
                    </button>
                </form>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Template Picker -->
                <div class="space-y-2 rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                    <h2 class="mb-2 text-xs font-bold uppercase tracking-wider text-slate-500">Available Templates</h2>
                    <button
                        v-for="t in templates"
                        :key="t.id"
                        type="button"
                        @click="selectedTemplateId = t.id"
                        class="flex w-full items-start gap-2.5 rounded-lg border p-3 text-left text-xs transition"
                        :class="selectedTemplateId === t.id ? 'border-sky-500 bg-sky-50 text-sky-950' : 'border-slate-200 hover:bg-slate-50'"
                    >
                        <FileText class="mt-0.5 h-4 w-4 shrink-0 text-sky-600" />
                        <div>
                            <div class="font-semibold">{{ t.name }}</div>
                            <div class="font-mono text-[10px] text-slate-500">{{ t.code }} · Prefix: {{ t.auto_number_prefix }} (Next #{{ t.next_sequence }})</div>
                        </div>
                    </button>
                </div>

                <!-- Mail-Merge Form -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-2">
                    <div v-if="!activeTemplate" class="text-sm text-slate-500">No active templates available.</div>
                    <form v-else @submit.prevent="submitGenerate" class="space-y-4 text-xs">
                        <div class="border-b border-slate-200 pb-3">
                            <h2 class="text-sm font-bold text-slate-900">Generate Document: {{ activeTemplate.name }}</h2>
                            <p class="font-mono text-[11px] text-slate-500">Auto-number format: {{ activeTemplate.auto_number_prefix }}/2026/{{ String(activeTemplate.next_sequence).padStart(4, '0') }}</p>
                        </div>

                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div>
                                <label class="mb-1 block font-medium text-slate-700">Document Title</label>
                                <input v-model="generateForm.title" type="text" required placeholder="Formal Record Title" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                            </div>
                            <div>
                                <label class="mb-1 block font-medium text-slate-700">Destination Cabinet</label>
                                <select v-model="generateForm.cabinet_id" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                                    <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block font-medium text-slate-700">Classification Level</label>
                                <select v-model="generateForm.classification_level_id" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                                    <option v-for="lvl in allowedClearances" :key="lvl.id" :value="lvl.id">L{{ lvl.rank }} — {{ lvl.name }}</option>
                                </select>
                            </div>
                        </div>

                        <div v-for="field in activeTemplate.placeholders" :key="field.key">
                            <label class="mb-1 block font-medium text-slate-700">
                                {{ field.label }} <span class="font-mono text-[10px] text-slate-400">({{ field.key }})</span>
                            </label>
                            <textarea
                                v-if="field.key.includes('body')"
                                v-model="generateForm.variables[field.key]"
                                rows="4"
                                :required="field.required !== false"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2"
                            />
                            <input
                                v-else
                                v-model="generateForm.variables[field.key]"
                                type="text"
                                :required="field.required !== false"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2"
                            />
                        </div>

                        <button type="submit" :disabled="generateForm.processing" class="rounded-lg bg-emerald-600 px-4 py-2.5 font-semibold text-white hover:bg-emerald-500">
                            Generate Encrypted PDF/A Record
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
