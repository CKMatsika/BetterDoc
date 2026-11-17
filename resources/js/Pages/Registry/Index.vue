<script setup lang="ts">
import { ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { BookMarked, Plus } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClassificationBadge from '@/Components/ClassificationBadge.vue';

const props = defineProps<{
    entries: {
        id: string;
        registry_ref_no: string;
        direction: string;
        external_party: string | null;
        security_folio_no: string | null;
        physical_file_barcode: string | null;
        dispatch_method: string | null;
        bring_up_date: string | null;
        status: string;
        registrar_name: string | null;
        document: {
            id: string;
            doc_number: string;
            title: string;
            classification_rank: number;
            classification_name: string | null;
            classification_color: string | null;
        } | null;
    }[];
    documents: { id: string; doc_number: string; title: string }[];
    filters: { direction: string; bring_up_due: boolean };
}>();

const showModal = ref(false);
const form = useForm({
    document_id: props.documents[0]?.id ?? '',
    direction: 'incoming',
    external_party: '',
    security_folio_no: '',
    physical_file_barcode: '',
    dispatch_method: 'Courier',
    bring_up_date: '',
});

function submit(): void {
    form.post('/registry', {
        onSuccess: () => {
            showModal.value = false;
            form.reset('external_party', 'security_folio_no', 'physical_file_barcode', 'bring_up_date');
        },
    });
}
</script>

<template>
    <Head title="Correspondence Registry" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="flex items-center gap-2 text-xl font-bold text-slate-900">
                        <BookMarked class="h-5 w-5 text-sky-600" />
                        <span>Correspondence Registry</span>
                    </h1>
                    <p class="text-xs text-slate-600">Incoming, outgoing, and internal folio register with bring-up dockets &amp; RLS enforcement.</p>
                </div>
                <button v-if="documents.length > 0" type="button" @click="showModal = true" class="inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-500">
                    <Plus class="h-4 w-4" /> Register Entry
                </button>
            </div>

            <div class="flex flex-wrap gap-2 text-xs">
                <button type="button" @click="router.get('/registry')" class="rounded-lg px-3 py-1.5 font-semibold" :class="!filters.direction && !filters.bring_up_due ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300'">All</button>
                <button type="button" @click="router.get('/registry', { direction: 'incoming' })" class="rounded-lg px-3 py-1.5 font-semibold" :class="filters.direction === 'incoming' ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300'">Incoming</button>
                <button type="button" @click="router.get('/registry', { direction: 'outgoing' })" class="rounded-lg px-3 py-1.5 font-semibold" :class="filters.direction === 'outgoing' ? 'bg-slate-900 text-white' : 'bg-white border border-slate-300'">Outgoing</button>
                <button type="button" @click="router.get('/registry', { bring_up_due: true })" class="rounded-lg px-3 py-1.5 font-semibold" :class="filters.bring_up_due ? 'bg-amber-600 text-white' : 'bg-white border border-slate-300'">Bring-Up Due (7d)</button>
            </div>

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                <table class="min-w-full divide-y divide-slate-200 text-xs">
                    <thead class="bg-slate-50 text-left font-semibold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Registry Ref</th>
                            <th class="px-4 py-3">Direction</th>
                            <th class="px-4 py-3">Linked Record</th>
                            <th class="px-4 py-3">Folio / Barcode</th>
                            <th class="px-4 py-3">Bring-Up / Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="entries.length === 0">
                            <td colspan="5" class="px-4 py-8 text-center text-slate-500">No correspondence entries visible at your clearance level.</td>
                        </tr>
                        <tr v-for="e in entries" :key="e.id" class="hover:bg-slate-50">
                            <td class="px-4 py-3 font-mono font-bold text-slate-900">{{ e.registry_ref_no }}</td>
                            <td class="px-4 py-3 uppercase font-semibold">{{ e.direction }}<div class="font-normal normal-case text-slate-500">{{ e.external_party }}</div></td>
                            <td class="px-4 py-3">
                                <Link v-if="e.document" :href="`/documents/${e.document.id}`" class="font-semibold text-sky-700 hover:underline">
                                    {{ e.document.title }}
                                </Link>
                                <div v-if="e.document" class="mt-1">
                                    <ClassificationBadge :name="e.document.classification_name ?? 'Class'" :color-hex="e.document.classification_color ?? '#475569'" :rank="e.document.classification_rank" />
                                </div>
                            </td>
                            <td class="px-4 py-3 font-mono text-slate-600">
                                <div>{{ e.security_folio_no ?? '—' }}</div>
                                <div class="text-[11px]">{{ e.physical_file_barcode ?? '' }}</div>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-mono">{{ e.bring_up_date ?? 'No Bring-Up' }}</div>
                                <span class="mt-1 inline-block rounded bg-slate-100 px-2 py-0.5 font-mono uppercase text-[11px]">{{ e.status }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
            <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                <h2 class="mb-4 text-lg font-bold text-slate-900">Register Correspondence Entry</h2>
                <form @submit.prevent="submit" class="space-y-3 text-xs">
                    <div>
                        <label class="mb-1 block font-medium text-slate-700">Document</label>
                        <select v-model="form.document_id" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option v-for="d in documents" :key="d.id" :value="d.id">{{ d.doc_number }} — {{ d.title }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block font-medium text-slate-700">Direction</label>
                            <select v-model="form.direction" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                                <option value="incoming">Incoming</option>
                                <option value="outgoing">Outgoing</option>
                                <option value="internal">Internal</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-medium text-slate-700">Bring-Up Date</label>
                            <input v-model="form.bring_up_date" type="date" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                        </div>
                    </div>
                    <input v-model="form.external_party" type="text" placeholder="External Ministry / Counterparty" class="w-full rounded-lg border border-slate-300 px-3 py-2" />
                    <div class="grid grid-cols-2 gap-3">
                        <input v-model="form.security_folio_no" type="text" placeholder="Security Folio No." class="rounded-lg border border-slate-300 px-3 py-2 font-mono" />
                        <input v-model="form.physical_file_barcode" type="text" placeholder="Physical Barcode" class="rounded-lg border border-slate-300 px-3 py-2 font-mono" />
                    </div>
                    <div class="flex justify-end gap-2 pt-3">
                        <button type="button" @click="showModal = false" class="rounded-lg border border-slate-300 px-4 py-2 font-medium">Cancel</button>
                        <button type="submit" :disabled="form.processing" class="rounded-lg bg-sky-600 px-4 py-2 font-semibold text-white">Register</button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
