<script setup lang="ts">
import { ref } from 'vue';
import { Upload, Pause, Play, CheckCircle2, AlertTriangle } from '@lucide/vue';

const props = defineProps<{
    documentId?: string;
    cabinets?: { id: string; name: string }[];
    allowedClearances?: { id: string; rank: number; name: string }[];
    userCompartments?: { id: string; code: string; name: string }[];
}>();

const emit = defineEmits<{
    (e: 'completed', payload: { document_id: string; version_id: string; version_label: string }): void;
}>();

const selectedFile = ref<File | null>(null);
const cabinetId = ref(props.cabinets?.[0]?.id ?? '');
const classificationId = ref(props.allowedClearances?.[0]?.id ?? '');
const docNumber = ref('');
const title = ref('');
const summary = ref('');
const compartmentIds = ref<string[]>([]);
const major = ref(false);
const versionNote = ref('');

const uploading = ref(false);
const paused = ref(false);
const progressPct = ref(0);
const statusText = ref('');
const errorText = ref<string | null>(null);

function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

async function sha256Hex(buffer: ArrayBuffer): Promise<string> {
    const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
    return Array.from(new Uint8Array(hashBuffer))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
}

function onFileSelected(event: Event): void {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0] ?? null;
    selectedFile.value = file;
    errorText.value = null;
    if (file && !title.value) {
        title.value = file.name.replace(/\.[^.]+$/, '');
    }
}

async function startOrResumeUpload(): Promise<void> {
    if (!selectedFile.value) {
        return;
    }

    const file = selectedFile.value;
    uploading.value = true;
    paused.value = false;
    errorText.value = null;

    const resumeKey = `betterdoc_upload_${file.name}_${file.size}_${file.lastModified}_${props.documentId ?? 'new'}`;

    try {
        statusText.value = 'Computing SHA-256 file digest...';
        const fullSha = await sha256Hex(await file.arrayBuffer());

        let sessionId = localStorage.getItem(resumeKey);
        let chunkSize = 1024 * 1024;
        let missingParts: number[] = [];

        if (sessionId) {
            statusText.value = 'Checking interrupted upload checkpoint...';
            const statusRes = await fetch(`/uploads/${sessionId}/status`, { credentials: 'same-origin' });
            if (statusRes.ok) {
                const st = (await statusRes.json()) as {
                    chunk_size: number;
                    missing_parts: number[];
                    total_chunks: number;
                };
                chunkSize = st.chunk_size;
                missingParts = st.missing_parts;
            } else {
                sessionId = null;
            }
        }

        if (!sessionId) {
            statusText.value = 'Initializing encrypted quarantine session...';
            const initRes = await fetch('/uploads/init', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    filename: file.name,
                    mime_type: file.type || 'application/octet-stream',
                    total_size: file.size,
                    sha256_expected: fullSha,
                    document_id: props.documentId ?? null,
                    chunk_size: 1048576,
                }),
            });
            if (!initRes.ok) {
                throw new Error('Failed to initialize upload session.');
            }
            const initData = (await initRes.json()) as {
                session_id: string;
                chunk_size: number;
                missing_parts: number[];
            };
            sessionId = initData.session_id;
            chunkSize = initData.chunk_size;
            missingParts = initData.missing_parts;
            localStorage.setItem(resumeKey, sessionId);
        }

        const totalChunks = Math.ceil(file.size / chunkSize);
        let doneCount = totalChunks - missingParts.length;
        progressPct.value = Math.round((doneCount / totalChunks) * 100);

        for (const partIdx of missingParts) {
            if (paused.value) {
                statusText.value = `Paused at ${progressPct.value}% — safe to resume later.`;
                uploading.value = false;
                return;
            }

            const start = partIdx * chunkSize;
            const end = Math.min(file.size, start + chunkSize);
            const slice = file.slice(start, end);
            const buf = await slice.arrayBuffer();
            const chunkSha = await sha256Hex(buf);

            statusText.value = `Uploading chunk ${partIdx + 1} of ${totalChunks}...`;
            const chunkRes = await fetch(`/uploads/${sessionId}/chunks/${partIdx}`, {
                method: 'PUT',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/octet-stream',
                    'X-Chunk-Sha256': chunkSha,
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: buf,
            });
            if (!chunkRes.ok) {
                throw new Error(`Chunk ${partIdx + 1} upload failed.`);
            }

            doneCount += 1;
            progressPct.value = Math.round((doneCount / totalChunks) * 100);
        }

        statusText.value = 'Running ClamAV scan, CDR sanitization, OCR & AES-GCM/XChaCha encryption...';
        const completeRes = await fetch(`/uploads/${sessionId}/complete`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                cabinet_id: cabinetId.value,
                classification_level_id: classificationId.value,
                doc_number: docNumber.value,
                title: title.value,
                summary: summary.value,
                compartment_ids: compartmentIds.value,
                major: major.value,
                version_note: versionNote.value,
            }),
        });

        const payload = (await completeRes.json()) as {
            message?: string;
            document_id?: string;
            version_id?: string;
            version_label?: string;
        };

        if (!completeRes.ok) {
            throw new Error(payload.message ?? 'Upload finalization failed.');
        }

        localStorage.removeItem(resumeKey);
        statusText.value = `Verified & encrypted (${payload.version_label}).`;
        uploading.value = false;

        if (payload.document_id && payload.version_id && payload.version_label) {
            emit('completed', {
                document_id: payload.document_id,
                version_id: payload.version_id,
                version_label: payload.version_label,
            });
        }
    } catch (e) {
        errorText.value = e instanceof Error ? e.message : 'Upload error';
        uploading.value = false;
    }
}
</script>

<template>
    <div class="space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
        <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
            <Upload class="h-4 w-4 text-sky-600" />
            <span>{{ documentId ? 'Check-In / Upload New Version (Resumable)' : 'Resumable Encrypted Upload (200MB+ Safe)' }}</span>
        </div>

        <div>
            <input type="file" @change="onFileSelected" class="block w-full text-xs text-slate-600 file:mr-3 file:rounded-lg file:border-0 file:bg-slate-900 file:px-3 file:py-2 file:text-xs file:font-semibold file:text-white hover:file:bg-slate-800" />
        </div>

        <div v-if="!documentId" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-700">Cabinet</label>
                <select v-model="cabinetId" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                    <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-700">Classification</label>
                <select v-model="classificationId" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                    <option v-for="l in allowedClearances" :key="l.id" :value="l.id">L{{ l.rank }} — {{ l.name }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-700">Reference Number</label>
                <input v-model="docNumber" type="text" required placeholder="AFZ/OPS/2026/120" class="w-full rounded-lg border border-slate-300 px-3 py-1.5 font-mono text-xs" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-700">Document Title</label>
                <input v-model="title" type="text" required class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
            </div>
        </div>

        <div v-if="!documentId && userCompartments && userCompartments.length > 0" class="flex flex-wrap gap-3 text-xs">
            <label v-for="comp in userCompartments" :key="comp.id" class="inline-flex items-center gap-1">
                <input type="checkbox" :value="comp.id" v-model="compartmentIds" />
                <span class="font-mono">{{ comp.code }}</span>
            </label>
        </div>

        <div v-if="documentId" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <label class="flex items-center gap-2 text-xs font-medium text-slate-700">
                <input type="checkbox" v-model="major" />
                <span>Create Major Version (v+1.0)</span>
            </label>
            <input v-model="versionNote" type="text" placeholder="Change summary / check-in note" class="rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
        </div>

        <div v-if="progressPct > 0 || statusText" class="space-y-1.5">
            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-100">
                <div class="h-full bg-sky-600 transition-all" :style="{ width: `${progressPct}%` }" />
            </div>
            <p class="flex items-center gap-1.5 font-mono text-[11px] text-slate-600">
                <CheckCircle2 v-if="progressPct === 100 && !uploading && !errorText" class="h-3.5 w-3.5 text-emerald-600" />
                <span>{{ statusText }} ({{ progressPct }}%)</span>
            </p>
        </div>

        <p v-if="errorText" class="flex items-center gap-1.5 rounded-lg bg-red-50 px-3 py-2 text-xs font-semibold text-red-700">
            <AlertTriangle class="h-4 w-4 shrink-0" />
            <span>{{ errorText }}</span>
        </p>

        <div class="flex items-center gap-2">
            <button
                type="button"
                @click="startOrResumeUpload"
                :disabled="!selectedFile || uploading"
                class="inline-flex items-center gap-1.5 rounded-lg bg-sky-600 px-4 py-2 text-xs font-semibold text-white hover:bg-sky-500 disabled:opacity-50"
            >
                <Play class="h-3.5 w-3.5" />
                <span>{{ paused ? 'Resume Upload' : 'Start Secure Upload' }}</span>
            </button>
            <button
                v-if="uploading"
                type="button"
                @click="paused = true"
                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 px-3 py-2 text-xs font-semibold text-slate-700"
            >
                <Pause class="h-3.5 w-3.5" />
                <span>Pause</span>
            </button>
        </div>
    </div>
</template>
