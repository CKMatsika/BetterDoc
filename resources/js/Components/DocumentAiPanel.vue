<script setup lang="ts">
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Bot, CopyCheck, FileSearch, Languages, Sparkles, Tags } from '@lucide/vue';

const props = defineProps<{
    documentId: string;
}>();

const loadingAction = ref<string | null>(null);
const outputTitle = ref<string | null>(null);
const outputBody = ref<string | null>(null);
const targetLang = ref<'sn' | 'nd' | 'en'>('sn');
const replyGuidance = ref('');

function xsrfHeader(): string {
    const m = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return m ? decodeURIComponent(m[1]) : '';
}

async function runAction(action: string, extra: Record<string, unknown> = {}): Promise<void> {
    loadingAction.value = action;
    try {
        const res = await fetch(`/documents/${props.documentId}/ai/action`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': xsrfHeader(),
            },
            body: JSON.stringify({ action, ...extra }),
        });
        if (!res.ok) {
            return;
        }
        const data = (await res.json()) as Record<string, unknown>;
        if (action === 'summarize') {
            outputTitle.value = 'AI Executive Summary';
            outputBody.value = String(data.summary ?? '');
        } else if (action === 'clauses') {
            outputTitle.value = 'Extracted Clauses';
            const clauses = (data.clauses as { category: string; excerpt: string }[]) ?? [];
            outputBody.value = clauses.map((c) => `[${c.category}] ${c.excerpt}`).join('\n\n');
        } else if (action === 'draft_reply') {
            outputTitle.value = `Draft Reply (Ceiling Rank L${String(data.high_water_rank ?? 0)})`;
            outputBody.value = String(data.draft ?? '');
        } else if (action === 'translate') {
            outputTitle.value = `Local Translation (${String(data.target_lang ?? targetLang.value).toUpperCase()})`;
            outputBody.value = String(data.translated_text ?? '');
        } else if (action === 'auto_tag') {
            outputTitle.value = 'Auto-Suggested & Applied Tags';
            outputBody.value = ((data.tags as string[]) ?? []).map((t) => `#${t}`).join(' ');
            router.reload({ only: ['document'] });
        } else if (action === 'duplicates') {
            outputTitle.value = 'Cleared Vault Duplicate Scan';
            const dups = (data.duplicates as { doc_number: string; title: string; similarity: number }[]) ?? [];
            outputBody.value = dups.length === 0 ? 'No near-duplicate documents detected within your clearance.' : dups.map((d) => `${d.doc_number} (${Math.round(d.similarity * 100)}%) — ${d.title}`).join('\n');
        }
    } finally {
        loadingAction.value = null;
    }
}

function triggerIdp(): void {
    router.post(`/documents/${props.documentId}/ai/idp`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-3 text-xs">
        <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
            <Bot class="h-4 w-4 text-sky-600" />
            <span>Local-First Document AI Tools</span>
        </div>

        <div class="grid grid-cols-2 gap-2">
            <button type="button" @click="triggerIdp" class="inline-flex items-center justify-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 font-semibold hover:bg-slate-50">
                <FileSearch class="h-3.5 w-3.5 text-indigo-600" /> Extract Fields (IDP)
            </button>
            <button type="button" @click="runAction('summarize')" :disabled="loadingAction !== null" class="inline-flex items-center justify-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 font-semibold hover:bg-slate-50">
                <Sparkles class="h-3.5 w-3.5 text-sky-600" /> Summarize
            </button>
            <button type="button" @click="runAction('clauses')" :disabled="loadingAction !== null" class="inline-flex items-center justify-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 font-semibold hover:bg-slate-50">
                Extract Clauses
            </button>
            <button type="button" @click="runAction('auto_tag', { apply_tags: true })" :disabled="loadingAction !== null" class="inline-flex items-center justify-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 font-semibold hover:bg-slate-50">
                <Tags class="h-3.5 w-3.5 text-emerald-600" /> Auto-Tag
            </button>
            <button type="button" @click="runAction('duplicates')" :disabled="loadingAction !== null" class="col-span-2 inline-flex items-center justify-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 font-semibold hover:bg-slate-50">
                <CopyCheck class="h-3.5 w-3.5 text-amber-600" /> Check Near-Duplicates (Clearance-Scoped)
            </button>
        </div>

        <div class="flex gap-2">
            <select v-model="targetLang" class="rounded border border-slate-300 px-2 py-1">
                <option value="sn">ChiShona (sn)</option>
                <option value="nd">IsiNdebele (nd)</option>
                <option value="en">English (en)</option>
            </select>
            <button type="button" @click="runAction('translate', { target_lang: targetLang })" :disabled="loadingAction !== null" class="flex-1 inline-flex items-center justify-center gap-1 rounded bg-slate-900 px-2.5 py-1 font-semibold text-white">
                <Languages class="h-3.5 w-3.5" /> Translate Locally
            </button>
        </div>

        <div class="flex gap-2">
            <input v-model="replyGuidance" type="text" placeholder="Reply draft guidance..." class="flex-1 rounded border border-slate-300 px-2 py-1" />
            <button type="button" @click="runAction('draft_reply', { instructions: replyGuidance })" :disabled="loadingAction !== null" class="rounded bg-sky-600 px-2.5 py-1 font-semibold text-white">
                Draft Reply
            </button>
        </div>

        <div v-if="outputBody !== null" class="rounded-lg border border-sky-200 bg-sky-50/50 p-3 space-y-1">
            <div class="font-bold text-sky-950">{{ outputTitle }}</div>
            <pre class="whitespace-pre-wrap font-sans text-[11px] text-slate-800">{{ outputBody }}</pre>
        </div>
    </div>
</template>
