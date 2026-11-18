<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import * as pdfjsLib from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { ChevronLeft, ChevronRight, ZoomIn, ZoomOut, Shield, Highlighter, MessageSquarePlus, Eraser, MousePointer } from '@lucide/vue';
import type { DocumentAnnotationItem } from '@/types/betterdoc';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

const props = withDefaults(
    defineProps<{
        streamUrl: string;
        mimeType: string;
        watermark: {
            org_name?: string;
            viewer: string;
            service_number: string;
            classification: string;
            ip: string;
            timestamp: string;
            break_glass: boolean;
        };
        annotations?: DocumentAnnotationItem[];
        versionId?: string | null;
        canAnnotate?: boolean;
        isWorm?: boolean;
    }>(),
    {
        annotations: () => [],
        versionId: null,
        canAnnotate: false,
        isWorm: false,
    }
);

const emit = defineEmits<{
    createAnnotation: [
        payload: {
            version_id: string | null;
            page_number: number;
            kind: 'note' | 'highlight' | 'redaction_mark';
            x: number;
            y: number;
            width: number;
            height: number;
            content: string | null;
        },
    ];
}>();

const canvasRef = ref<HTMLCanvasElement | null>(null);
const overlayRef = ref<HTMLDivElement | null>(null);
const currentPage = ref(1);
const totalPages = ref(1);
const scale = ref(1.2);
const loading = ref(true);
const errorMsg = ref<string | null>(null);
let pdfDoc: pdfjsLib.PDFDocumentProxy | null = null;

const toolMode = ref<'view' | 'highlight' | 'note' | 'redaction_mark'>('view');
const noteText = ref('');
const dragStart = ref<{ x: number; y: number } | null>(null);
const dragCurrent = ref<{ x: number; y: number } | null>(null);

const pageAnnotations = computed(() =>
    props.annotations.filter(
        (a) =>
            a.page_number === currentPage.value &&
            (!props.versionId || a.version_id === props.versionId) &&
            a.redacted_in_version_id === null
    )
);

const previewDraftRect = computed(() => {
    if (!dragStart.value || !dragCurrent.value) {
        return null;
    }
    const x = Math.min(dragStart.value.x, dragCurrent.value.x);
    const y = Math.min(dragStart.value.y, dragCurrent.value.y);
    const width = Math.abs(dragCurrent.value.x - dragStart.value.x);
    const height = Math.abs(dragCurrent.value.y - dragStart.value.y);
    return { x, y, width, height };
});

async function loadDocument(): Promise<void> {
    if (props.mimeType !== 'application/pdf') {
        loading.value = false;
        return;
    }

    loading.value = true;
    errorMsg.value = null;

    try {
        const task = pdfjsLib.getDocument({
            url: props.streamUrl,
            withCredentials: true,
        });
        pdfDoc = await task.promise;
        totalPages.value = pdfDoc.numPages;
        currentPage.value = 1;
        await renderPage(1);
    } catch (e) {
        errorMsg.value = e instanceof Error ? e.message : 'Unable to render encrypted PDF.';
    } finally {
        loading.value = false;
    }
}

async function renderPage(num: number): Promise<void> {
    if (!pdfDoc || !canvasRef.value) {
        return;
    }
    const page = await pdfDoc.getPage(num);
    const viewport = page.getViewport({ scale: scale.value });
    const canvas = canvasRef.value;
    const context = canvas.getContext('2d');
    if (!context) {
        return;
    }

    canvas.height = viewport.height;
    canvas.width = viewport.width;

    await page.render({
        canvas,
        canvasContext: context,
        viewport,
    }).promise;
}

function normalizedPoint(event: MouseEvent): { x: number; y: number } | null {
    const el = overlayRef.value;
    if (!el) {
        return null;
    }
    const rect = el.getBoundingClientRect();
    if (rect.width <= 0 || rect.height <= 0) {
        return null;
    }
    return {
        x: Math.max(0, Math.min(1, (event.clientX - rect.left) / rect.width)),
        y: Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height)),
    };
}

function onPointerDown(event: MouseEvent): void {
    if (toolMode.value === 'view' || !props.canAnnotate || props.isWorm) {
        return;
    }
    const pt = normalizedPoint(event);
    if (!pt) {
        return;
    }
    dragStart.value = pt;
    dragCurrent.value = pt;
}

function onPointerMove(event: MouseEvent): void {
    if (!dragStart.value) {
        return;
    }
    const pt = normalizedPoint(event);
    if (pt) {
        dragCurrent.value = pt;
    }
}

function onPointerUp(): void {
    const draft = previewDraftRect.value;
    dragStart.value = null;
    dragCurrent.value = null;

    if (!draft || draft.width < 0.015 || draft.height < 0.012 || toolMode.value === 'view') {
        return;
    }

    emit('createAnnotation', {
        version_id: props.versionId ?? null,
        page_number: currentPage.value,
        kind: toolMode.value,
        x: Number(draft.x.toFixed(4)),
        y: Number(draft.y.toFixed(4)),
        width: Number(draft.width.toFixed(4)),
        height: Number(draft.height.toFixed(4)),
        content: noteText.value.trim() !== '' ? noteText.value.trim() : null,
    });

    noteText.value = '';
}

function prevPage(): void {
    if (currentPage.value > 1) {
        currentPage.value -= 1;
        void renderPage(currentPage.value);
    }
}

function nextPage(): void {
    if (currentPage.value < totalPages.value) {
        currentPage.value += 1;
        void renderPage(currentPage.value);
    }
}

function zoomIn(): void {
    scale.value = Math.min(2.4, Number((scale.value + 0.2).toFixed(1)));
    void renderPage(currentPage.value);
}

function zoomOut(): void {
    scale.value = Math.max(0.6, Number((scale.value - 0.2).toFixed(1)));
    void renderPage(currentPage.value);
}

onMounted(() => {
    void loadDocument();
});

watch(() => props.streamUrl, () => {
    void loadDocument();
});
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-slate-300 bg-slate-900 shadow-sm">
        <!-- Viewer Toolbar -->
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-800 bg-slate-950 px-4 py-2 text-xs text-slate-200">
            <div class="flex items-center gap-2 font-mono">
                <Shield class="h-4 w-4 text-sky-400" />
                <span>SELF-HOSTED PDF.JS · ENCRYPTED STREAM</span>
            </div>

            <div v-if="mimeType === 'application/pdf' && canAnnotate && !isWorm" class="flex flex-wrap items-center gap-1.5">
                <button
                    type="button"
                    @click="toolMode = 'view'"
                    class="inline-flex items-center gap-1 rounded px-2 py-1 font-medium"
                    :class="toolMode === 'view' ? 'bg-sky-600 text-white' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                >
                    <MousePointer class="h-3.5 w-3.5" /> View
                </button>
                <button
                    type="button"
                    @click="toolMode = 'highlight'"
                    class="inline-flex items-center gap-1 rounded px-2 py-1 font-medium"
                    :class="toolMode === 'highlight' ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                >
                    <Highlighter class="h-3.5 w-3.5" /> Highlight
                </button>
                <button
                    type="button"
                    @click="toolMode = 'note'"
                    class="inline-flex items-center gap-1 rounded px-2 py-1 font-medium"
                    :class="toolMode === 'note' ? 'bg-sky-500 text-slate-950' : 'bg-slate-800 text-slate-300 hover:bg-slate-700'"
                >
                    <MessageSquarePlus class="h-3.5 w-3.5" /> Note
                </button>
                <button
                    type="button"
                    @click="toolMode = 'redaction_mark'"
                    class="inline-flex items-center gap-1 rounded px-2 py-1 font-semibold"
                    :class="toolMode === 'redaction_mark' ? 'bg-red-600 text-white' : 'bg-slate-800 text-red-300 hover:bg-slate-700'"
                >
                    <Eraser class="h-3.5 w-3.5" /> Mark Redaction
                </button>
                <input
                    v-if="toolMode !== 'view'"
                    v-model="noteText"
                    type="text"
                    placeholder="Optional note / reason..."
                    class="w-40 rounded border border-slate-700 bg-slate-900 px-2 py-1 text-xs text-white placeholder-slate-400"
                />
            </div>

            <div v-if="mimeType === 'application/pdf'" class="flex items-center gap-2">
                <button type="button" @click="prevPage" :disabled="currentPage <= 1" class="rounded bg-slate-800 p-1 hover:bg-slate-700 disabled:opacity-40">
                    <ChevronLeft class="h-4 w-4" />
                </button>
                <span class="font-mono">Page {{ currentPage }} / {{ totalPages }}</span>
                <button type="button" @click="nextPage" :disabled="currentPage >= totalPages" class="rounded bg-slate-800 p-1 hover:bg-slate-700 disabled:opacity-40">
                    <ChevronRight class="h-4 w-4" />
                </button>
                <span class="mx-1 text-slate-600">|</span>
                <button type="button" @click="zoomOut" class="rounded bg-slate-800 p-1 hover:bg-slate-700">
                    <ZoomOut class="h-4 w-4" />
                </button>
                <span class="font-mono">{{ Math.round(scale * 100) }}%</span>
                <button type="button" @click="zoomIn" class="rounded bg-slate-800 p-1 hover:bg-slate-700">
                    <ZoomIn class="h-4 w-4" />
                </button>
            </div>
        </div>

        <div v-if="toolMode !== 'view'" class="border-b border-slate-800 bg-slate-900/90 px-4 py-1.5 text-[11px] text-amber-300">
            Drag a box directly over the page to place a <span class="font-bold uppercase">{{ toolMode.replace('_', ' ') }}</span> region. Redaction marks are physically purged once committed.
        </div>

        <!-- Viewport with Annotations & Dynamic Forensic Watermark Overlay -->
        <div class="relative flex min-h-[420px] items-center justify-center overflow-auto bg-slate-800 p-4">
            <div v-if="loading" class="font-mono text-xs text-slate-300">Decrypting envelope &amp; rendering pages locally...</div>
            <div v-else-if="errorMsg" class="rounded-lg bg-red-950 px-4 py-3 font-mono text-xs text-red-200">{{ errorMsg }}</div>
            <div v-else-if="mimeType !== 'application/pdf'" class="font-mono text-xs text-slate-300">
                Preview not rendered in PDF canvas for MIME {{ mimeType }}; launch Collabora Online WOPI Co-Editing or authorized download.
            </div>

            <div
                v-show="mimeType === 'application/pdf' && !loading && !errorMsg"
                ref="overlayRef"
                @mousedown="onPointerDown"
                @mousemove="onPointerMove"
                @mouseup="onPointerUp"
                class="relative inline-block shadow-2xl"
                :class="toolMode !== 'view' ? 'cursor-crosshair select-none' : ''"
            >
                <canvas ref="canvasRef" class="block bg-white" />

                <!-- Saved Annotations & Pending Redaction Boxes -->
                <div class="pointer-events-none absolute inset-0">
                    <div
                        v-for="ann in pageAnnotations"
                        :key="ann.id"
                        class="absolute"
                        :style="{
                            left: `${ann.coords.x * 100}%`,
                            top: `${ann.coords.y * 100}%`,
                            width: `${ann.coords.width * 100}%`,
                            height: `${ann.coords.height * 100}%`,
                        }"
                        :class="
                            ann.kind === 'redaction_mark'
                                ? 'border-2 border-red-600 bg-slate-950/75'
                                : ann.kind === 'highlight'
                                  ? 'bg-amber-300/40 border border-amber-500/60'
                                  : 'border-2 border-sky-600 bg-sky-400/20'
                        "
                    >
                        <span
                            v-if="ann.kind === 'redaction_mark'"
                            class="block truncate bg-red-600 px-1 font-mono text-[9px] font-bold text-white uppercase"
                        >
                            REDACT
                        </span>
                        <span
                            v-else-if="ann.content"
                            class="block truncate bg-slate-900/85 px-1 text-[9px] text-white"
                        >
                            {{ ann.content }}
                        </span>
                    </div>

                    <div
                        v-if="previewDraftRect"
                        class="absolute border-2 border-dashed border-red-500 bg-red-500/20"
                        :style="{
                            left: `${previewDraftRect.x * 100}%`,
                            top: `${previewDraftRect.y * 100}%`,
                            width: `${previewDraftRect.width * 100}%`,
                            height: `${previewDraftRect.height * 100}%`,
                        }"
                    />
                </div>

                <!-- Anti-Screenshot Dynamic Repeating Diagonal Watermark -->
                <div class="pointer-events-none absolute inset-0 overflow-hidden select-none">
                    <div class="grid h-full w-full grid-cols-2 grid-rows-3 place-items-center opacity-25">
                        <div
                            v-for="n in 6"
                            :key="n"
                            class="-rotate-30 text-center font-mono text-[11px] font-bold tracking-wider uppercase"
                            :class="watermark.break_glass ? 'text-red-700' : 'text-slate-700'"
                        >
                            <div>
                                {{ watermark.org_name ? `${watermark.org_name} · ` : '' }}{{ watermark.break_glass ? 'BREAK-GLASS EMERGENCY ACCESS' : watermark.classification }}
                            </div>
                            <div>{{ watermark.viewer }} ({{ watermark.service_number }})</div>
                            <div>{{ watermark.ip }} · {{ watermark.timestamp }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
