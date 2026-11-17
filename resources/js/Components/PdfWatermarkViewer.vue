<script setup lang="ts">
import { onMounted, ref, watch } from 'vue';
import * as pdfjsLib from 'pdfjs-dist';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.min.mjs?url';
import { ChevronLeft, ChevronRight, ZoomIn, ZoomOut, Shield } from '@lucide/vue';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

const props = defineProps<{
    streamUrl: string;
    mimeType: string;
    watermark: {
        viewer: string;
        service_number: string;
        classification: string;
        ip: string;
        timestamp: string;
        break_glass: boolean;
    };
}>();

const canvasRef = ref<HTMLCanvasElement | null>(null);
const currentPage = ref(1);
const totalPages = ref(1);
const scale = ref(1.2);
const loading = ref(true);
const errorMsg = ref<string | null>(null);
let pdfDoc: pdfjsLib.PDFDocumentProxy | null = null;

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

        <!-- Viewport with Dynamic Forensic Watermark Overlay -->
        <div class="relative flex min-h-[420px] items-center justify-center overflow-auto bg-slate-800 p-4">
            <div v-if="loading" class="font-mono text-xs text-slate-300">Decrypting envelope &amp; rendering pages locally...</div>
            <div v-else-if="errorMsg" class="rounded-lg bg-red-950 px-4 py-3 font-mono text-xs text-red-200">{{ errorMsg }}</div>
            <div v-else-if="mimeType !== 'application/pdf'" class="font-mono text-xs text-slate-300">
                Preview not rendered in PDF canvas for MIME {{ mimeType }}; use authorized download if permitted.
            </div>

            <div v-show="mimeType === 'application/pdf' && !loading && !errorMsg" class="relative inline-block shadow-2xl">
                <canvas ref="canvasRef" class="block bg-white" />

                <!-- Anti-Screenshot Dynamic Repeating Diagonal Watermark -->
                <div class="pointer-events-none absolute inset-0 overflow-hidden select-none">
                    <div class="grid h-full w-full grid-cols-2 grid-rows-3 place-items-center opacity-25">
                        <div
                            v-for="n in 6"
                            :key="n"
                            class="-rotate-30 text-center font-mono text-[11px] font-bold tracking-wider uppercase"
                            :class="watermark.break_glass ? 'text-red-700' : 'text-slate-700'"
                        >
                            <div>{{ watermark.break_glass ? 'BREAK-GLASS EMERGENCY ACCESS' : watermark.classification }}</div>
                            <div>{{ watermark.viewer }} ({{ watermark.service_number }})</div>
                            <div>{{ watermark.ip }} · {{ watermark.timestamp }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
