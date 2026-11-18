<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    Camera,
    Download,
    Eye,
    FileScan,
    HardDrive,
    KeyRound,
    Laptop,
    Printer as PrinterIcon,
    QrCode,
    RefreshCw,
    ShieldAlert,
    Smartphone,
    UploadCloud,
    WifiOff,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    enqueueOfflineScan,
    listOfflineScans,
    removeOfflineScan,
    type OfflineQueuedScan,
} from '@/lib/offlineScanQueue';

interface PrinterItem {
    id: string;
    name: string;
    location: string | null;
    cups_queue_name: string;
    ipps_uri: string;
    driver: string;
    max_classification_rank: number;
    supports_secure_release: boolean;
    supports_duplex: boolean;
    is_online: boolean;
}

interface CostCentreItem {
    id: string;
    code: string;
    name: string;
    monthly_page_quota: number;
    pages_used_month: number;
    remaining_pages: number;
    is_active: boolean;
}

interface PrintJobItem {
    id: string;
    document_id: string;
    doc_number: string;
    document_title: string;
    printer_id: string;
    printer_name: string;
    cost_centre_code: string | null;
    user_name: string;
    is_mine: boolean;
    status: string;
    copies: number;
    page_count: number;
    pin_attempts: number;
    classification_rank: number;
    watermark_stamp_text: string | null;
    cups_job_id: string | null;
    has_spool_preview: boolean;
    held_at: string | null;
    released_at: string | null;
}

interface ScanSourceItem {
    id: string;
    name: string;
    source_type: string;
    endpoint_or_path: string;
    cabinet_name: string | null;
    classification_name: string | null;
    auto_ocr: boolean;
    auto_split_barcode: boolean;
    auto_deskew: boolean;
    is_active: boolean;
    last_polled_at: string | null;
}

interface ScanBatchItem {
    id: string;
    source_channel: string;
    original_filename: string;
    cabinet_name: string;
    page_count: number;
    split_documents_count: number;
    status: string;
    report: {
        created_doc_ids?: string[];
        separators_found?: number;
        deskew_applied?: boolean;
        error?: string;
    };
    created_at: string | null;
}

interface LocalAgentItem {
    id: string;
    workstation_name: string;
    agent_version: string;
    status: string;
    capabilities: {
        twain_scanners?: string[];
        wia_scanners?: string[];
        pkcs11_tokens?: string[];
    };
    last_seen_at: string | null;
    paired_ip: string | null;
}

const props = defineProps<{
    printers: PrinterItem[];
    costCentres: CostCentreItem[];
    printJobs: PrintJobItem[];
    scanSources: ScanSourceItem[];
    scanBatches: ScanBatchItem[];
    localAgents: LocalAgentItem[];
    cabinets: { id: string; name: string }[];
    allowedClearances: { id: string; rank: number; code: string; name: string }[];
    clearedDocuments: { id: string; doc_number: string; title: string; classification_rank: number }[];
    userRfidBadge: string | null;
    canManageDevices: boolean;
}>();

const activeTab = ref<'print' | 'scan' | 'pwa' | 'agent'>('print');

// Tab 1: Queue Print & PIN / RFID Release
const quickPrintForm = useForm({
    document_id: props.clearedDocuments[0]?.id ?? '',
    printer_id: props.printers[0]?.id ?? '',
    cost_centre_id: props.costCentres[0]?.id ?? '',
    copies: 1,
});

const pinInputs = ref<Record<string, string>>({});
const rfidForm = useForm({
    rfid_badge_uid: props.userRfidBadge ?? '',
    printer_id: props.printers[0]?.id ?? '',
});

const printerForm = useForm({
    name: '',
    location: '',
    cups_queue_name: '',
    ipps_uri: 'http://cups:631/printers/',
    driver: 'local_spool',
    max_classification_rank: 1,
    supports_secure_release: true,
});

const discoveredPrinters = ref<{ name: string; cups_queue_name: string; ipps_uri: string; driver: string }[]>([]);

// Tab 2: Separator Generator + Batch Scanner + Scan Sources
const sepDocType = ref('invoice');
const sepTitle = ref('ZIMRA AP Vendor Batch');
const sepCabinet = ref(props.cabinets[0]?.name ?? 'General');
const sepClassification = ref(props.allowedClearances[0]?.name ?? 'Internal');

const scanBatchForm = useForm<{
    file: File | null;
    cabinet_id: string;
    classification_level_id: string;
    auto_split_barcode: boolean;
    auto_deskew: boolean;
    auto_idp: boolean;
    title_prefix: string;
}>({
    file: null,
    cabinet_id: props.cabinets[0]?.id ?? '',
    classification_level_id: props.allowedClearances[0]?.id ?? '',
    auto_split_barcode: true,
    auto_deskew: true,
    auto_idp: true,
    title_prefix: '',
});

const sourceForm = useForm({
    name: '',
    source_type: 'hot_folder',
    endpoint_or_path: 'custom-inbox',
    target_cabinet_id: props.cabinets[0]?.id ?? '',
    default_classification_level_id: props.allowedClearances[0]?.id ?? '',
    auto_split_barcode: true,
    auto_deskew: true,
});

// Tab 3: PWA Offline Camera IndexedDB Queue
const offlineQueue = ref<OfflineQueuedScan[]>([]);
const pwaOfflineSimulated = ref(false);
const pwaSyncBusy = ref(false);
const pwaStatusMsg = ref('');
const pwaCaptureTitle = ref('Mobile Field Scan');
const pwaCabinetId = ref(props.cabinets[0]?.id ?? '');
const pwaLevelId = ref(props.allowedClearances[0]?.id ?? '');

// Tab 4: Workstation Local Agent
const agentForm = useForm({
    workstation_name: 'Registry-Desk-PC-01',
});

async function refreshOfflineQueue(): Promise<void> {
    try {
        offlineQueue.value = await listOfflineScans();
    } catch {
        offlineQueue.value = [];
    }
}

onMounted(() => {
    refreshOfflineQueue();
});

function submitQuickPrint(): void {
    if (!quickPrintForm.document_id) {
        return;
    }
    quickPrintForm.post(`/documents/${quickPrintForm.document_id}/print`, { preserveScroll: true });
}

function submitReleasePin(jobId: string): void {
    router.post(
        `/print-scan/jobs/${jobId}/release`,
        { pin: pinInputs.value[jobId] ?? '' },
        { preserveScroll: true }
    );
}

function submitRfidRelease(): void {
    rfidForm.post('/print-scan/release-rfid', { preserveScroll: true });
}

async function discoverCups(): Promise<void> {
    const res = await fetch('/print-scan/printers/discover', {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '',
            Accept: 'application/json',
        },
        credentials: 'same-origin',
    });
    if (res.ok) {
        const data = (await res.json()) as { discovered: { name: string; cups_queue_name: string; ipps_uri: string; driver: string }[] };
        discoveredPrinters.value = data.discovered;
    }
}

function submitPrinter(): void {
    printerForm.post('/print-scan/printers', {
        preserveScroll: true,
        onSuccess: () => printerForm.reset('name', 'location', 'cups_queue_name'),
    });
}

function separatorDownloadUrl(): string {
    const q = new URLSearchParams({
        doc_type: sepDocType.value,
        title: sepTitle.value,
        cabinet_code: sepCabinet.value,
        classification_name: sepClassification.value,
    });
    return `/print-scan/separator-sheet?${q.toString()}`;
}

function onScanFileSelect(e: Event): void {
    const input = e.target as HTMLInputElement;
    scanBatchForm.file = input.files?.[0] ?? null;
}

function submitScanBatch(): void {
    scanBatchForm.post('/print-scan/scan-batches', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            scanBatchForm.file = null;
        },
    });
}

function submitScanSource(): void {
    sourceForm.post('/print-scan/scan-sources', {
        preserveScroll: true,
        onSuccess: () => sourceForm.reset('name'),
    });
}

function pollSource(sourceId: string): void {
    router.post(`/print-scan/scan-sources/${sourceId}/poll`, {}, { preserveScroll: true });
}

async function onPwaCameraCapture(e: Event): Promise<void> {
    const input = e.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) {
        return;
    }

    const item: OfflineQueuedScan = {
        id: crypto.randomUUID(),
        filename: file.name,
        mime_type: file.type || 'application/octet-stream',
        size_bytes: file.size,
        cabinet_id: pwaCabinetId.value,
        classification_level_id: pwaLevelId.value,
        title: pwaCaptureTitle.value || file.name,
        auto_split_barcode: true,
        auto_deskew: true,
        queued_at: new Date().toISOString(),
        blob: file,
    };

    await enqueueOfflineScan(item);
    await refreshOfflineQueue();
    pwaStatusMsg.value = pwaOfflineSimulated.value
        ? `Saved '${file.name}' to IndexedDB offline store while disconnected from LAN.`
        : `Queued '${file.name}' in IndexedDB. Click Sync Now to upload.`;
    input.value = '';
}

async function syncOfflineQueue(): Promise<void> {
    if (pwaOfflineSimulated.value) {
        pwaStatusMsg.value = 'LAN link simulated offline — items safely preserved inside IndexedDB.';
        return;
    }

    pwaSyncBusy.value = true;
    let synced = 0;
    try {
        const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
        for (const item of [...offlineQueue.value]) {
            const fd = new FormData();
            fd.append('file', item.blob, item.filename);
            fd.append('cabinet_id', item.cabinet_id);
            fd.append('classification_level_id', item.classification_level_id);
            fd.append('auto_split_barcode', item.auto_split_barcode ? '1' : '0');
            fd.append('auto_deskew', item.auto_deskew ? '1' : '0');
            fd.append('source_channel', 'pwa_camera');
            fd.append('title_prefix', item.title);

            const resp = await fetch('/print-scan/scan-batches', {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': csrf,
                    Accept: 'application/json',
                },
                body: fd,
                credentials: 'same-origin',
            });

            if (resp.ok) {
                await removeOfflineScan(item.id);
                synced++;
            }
        }
        await refreshOfflineQueue();
        pwaStatusMsg.value = `Synced ${synced} offline scan(s) cleanly into SENTINEL Vault.`;
        if (synced > 0) {
            router.reload({ only: ['scanBatches'] });
        }
    } finally {
        pwaSyncBusy.value = false;
    }
}

function submitAgentPairInit(): void {
    agentForm.post('/print-scan/agents/pair-init', { preserveScroll: true });
}

function revokeAgent(agentId: string): void {
    router.post(`/print-scan/agents/${agentId}/revoke`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Secure Print, Scan Capture, PWA & Workstation Agent" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Secure Printing, Batch Scanning, Offline PWA &amp; Local Agent</h1>
                    <p class="text-xs text-slate-600">
                        CUPS/IPPS Clearance-Capped Printers · 6-Digit PIN &amp; RFID Hold Queue · 2D/QR Barcode Batch Splitter · IndexedDB Offline Camera · 127.0.0.1 USB TWAIN/WIA Bridge
                    </p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="tab in [
                            { id: 'print', label: 'Print Kiosk & Queues', count: printJobs.filter((j) => j.status === 'held').length },
                            { id: 'scan', label: 'Batch Scan & Separators', count: scanBatches.length },
                            { id: 'pwa', label: 'PWA Offline Capture', count: offlineQueue.length },
                            { id: 'agent', label: 'Local Agent (USB / Smartcard)', count: localAgents.filter((a) => a.status === 'paired').length },
                        ]"
                        :key="tab.id"
                        type="button"
                        @click="activeTab = tab.id as 'print' | 'scan' | 'pwa' | 'agent'"
                        class="rounded-lg border px-3 py-1.5 text-xs font-semibold transition"
                        :class="activeTab === tab.id ? 'border-sky-600 bg-sky-700 text-white' : 'border-slate-300 bg-white text-slate-700 hover:bg-slate-50'"
                    >
                        {{ tab.label }} <span class="ml-1 rounded bg-slate-950/15 px-1.5 py-0.5 text-[10px]">{{ tab.count }}</span>
                    </button>
                </div>
            </div>

            <!-- TAB 1: SECURE PRINT KIOSK, WATERMARKED PREVIEW & CUPS CEILINGS -->
            <div v-if="activeTab === 'print'" class="space-y-6">
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                    <!-- Quick Print Queue -->
                    <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs">
                        <h2 class="mb-3 flex items-center gap-2 font-bold text-slate-900">
                            <PrinterIcon class="h-4 w-4 text-sky-700" />
                            <span>Queue Watermarked Print Job</span>
                        </h2>
                        <form @submit.prevent="submitQuickPrint" class="space-y-3">
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Document</label>
                                <select v-model="quickPrintForm.document_id" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5">
                                    <option v-for="d in clearedDocuments" :key="d.id" :value="d.id">
                                        [L{{ d.classification_rank }}] {{ d.doc_number }} — {{ d.title }}
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Printer (Clearance Ceiling)</label>
                                <select v-model="quickPrintForm.printer_id" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5">
                                    <option v-for="p in printers" :key="p.id" :value="p.id">
                                        {{ p.name }} (Max L{{ p.max_classification_rank }} · {{ p.supports_secure_release ? 'PIN Hold' : 'Direct' }})
                                    </option>
                                </select>
                                <p v-if="quickPrintForm.errors.printer_id" class="mt-1 font-semibold text-red-700">{{ quickPrintForm.errors.printer_id }}</p>
                            </div>
                            <div class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="mb-1 block font-semibold text-slate-700">Cost Centre</label>
                                    <select v-model="quickPrintForm.cost_centre_id" class="w-full rounded-lg border border-slate-300 px-2 py-1.5">
                                        <option v-for="cc in costCentres" :key="cc.id" :value="cc.id">{{ cc.code }} ({{ cc.remaining_pages }}p)</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="mb-1 block font-semibold text-slate-700">Copies</label>
                                    <input v-model.number="quickPrintForm.copies" type="number" min="1" max="50" class="w-full rounded-lg border border-slate-300 px-2 py-1.5" />
                                </div>
                            </div>
                            <button type="submit" :disabled="quickPrintForm.processing" class="w-full rounded-lg bg-sky-700 py-2 font-semibold text-white hover:bg-sky-600">
                                Stamp Watermark &amp; Hold at Device
                            </button>
                        </form>
                    </div>

                    <!-- RFID / NFC Badge Tap Release -->
                    <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs">
                        <h2 class="mb-3 flex items-center gap-2 font-bold text-slate-900">
                            <KeyRound class="h-4 w-4 text-emerald-700" />
                            <span>MFP Badge Tap Release Kiosk (RFID / NFC)</span>
                        </h2>
                        <p class="mb-3 text-slate-600">
                            Tap employee badge at any secure printer terminal to release all eligible held jobs up to the device's max classification rank.
                        </p>
                        <form @submit.prevent="submitRfidRelease" class="space-y-3">
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Target Printer Terminal</label>
                                <select v-model="rfidForm.printer_id" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5">
                                    <option v-for="p in printers" :key="p.id" :value="p.id">{{ p.name }} (Max L{{ p.max_classification_rank }})</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">RFID / NFC Badge UID</label>
                                <input v-model="rfidForm.rfid_badge_uid" type="text" placeholder="e.g. BADGE-AFZ-0142" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono" />
                                <p v-if="rfidForm.errors.rfid_badge_uid" class="mt-1 font-semibold text-red-700">{{ rfidForm.errors.rfid_badge_uid }}</p>
                            </div>
                            <button type="submit" :disabled="rfidForm.processing" class="w-full rounded-lg bg-emerald-700 py-2 font-semibold text-white hover:bg-emerald-600">
                                Tap Badge &amp; Release Eligible Jobs
                            </button>
                        </form>
                    </div>

                    <!-- Cost Centre Quotas & Registered Printers -->
                    <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs">
                        <div class="mb-3 flex items-center justify-between">
                            <h2 class="font-bold text-slate-900">Cost Centre Monthly Quotas</h2>
                            <button v-if="canManageDevices" type="button" @click="discoverCups" class="inline-flex items-center gap-1 rounded border border-slate-300 px-2 py-1 text-[11px] font-semibold hover:bg-slate-50">
                                <RefreshCw class="h-3 w-3" /> Discover CUPS
                            </button>
                        </div>
                        <div class="space-y-2">
                            <div v-for="cc in costCentres" :key="cc.id" class="rounded-lg border border-slate-200 p-2.5">
                                <div class="flex justify-between font-semibold">
                                    <span>{{ cc.code }} — {{ cc.name }}</span>
                                    <span class="font-mono">{{ cc.pages_used_month }}/{{ cc.monthly_page_quota }}</span>
                                </div>
                                <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                                    <div
                                        class="h-full bg-sky-600"
                                        :style="{ width: `${Math.min(100, Math.round((cc.pages_used_month / Math.max(1, cc.monthly_page_quota)) * 100))}%` }"
                                    />
                                </div>
                            </div>
                        </div>
                        <div v-if="discoveredPrinters.length > 0" class="mt-3 rounded border border-sky-200 bg-sky-50 p-2 text-[11px]">
                            <p class="font-bold text-sky-900">Discovered LAN CUPS Queues:</p>
                            <p v-for="dp in discoveredPrinters" :key="dp.cups_queue_name" class="font-mono text-sky-800">{{ dp.name }} ({{ dp.ipps_uri }})</p>
                        </div>
                    </div>
                </div>

                <!-- Held & Historical Print Jobs Table -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs">
                    <h2 class="mb-3 font-bold uppercase tracking-wider text-slate-700">Secure Print Hold Queue &amp; Audit History (3 Invalid PINs Lock Job)</h2>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left">
                            <thead class="border-b border-slate-200 text-[11px] uppercase text-slate-500">
                                <tr>
                                    <th class="py-2 pr-3">Document</th>
                                    <th class="py-2 pr-3">Printer</th>
                                    <th class="py-2 pr-3">Status</th>
                                    <th class="py-2 pr-3">Pages / Quota</th>
                                    <th class="py-2 pr-3">Mandatory Watermark</th>
                                    <th class="py-2">Secure PIN Release / Spool</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="job in printJobs" :key="job.id">
                                    <td class="py-2.5 pr-3">
                                        <Link :href="`/documents/${job.document_id}`" class="font-semibold text-sky-700 hover:underline">{{ job.doc_number }}</Link>
                                        <div class="text-[11px] text-slate-500">{{ job.document_title }} · {{ job.user_name }}</div>
                                    </td>
                                    <td class="py-2.5 pr-3">{{ job.printer_name }}</td>
                                    <td class="py-2.5 pr-3">
                                        <span
                                            class="rounded px-2 py-0.5 font-mono text-[10px] font-bold uppercase"
                                            :class="{
                                                'bg-amber-100 text-amber-900': job.status === 'held',
                                                'bg-emerald-100 text-emerald-900': job.status === 'printed',
                                                'bg-red-100 text-red-900': job.status === 'locked' || job.status === 'failed',
                                            }"
                                        >
                                            {{ job.status }}
                                        </span>
                                        <span v-if="job.pin_attempts > 0" class="ml-1 text-[10px] text-red-600">({{ job.pin_attempts }}/3 tries)</span>
                                    </td>
                                    <td class="py-2.5 pr-3 font-mono">{{ job.page_count }} × {{ job.copies }} <span v-if="job.cost_centre_code">({{ job.cost_centre_code }})</span></td>
                                    <td class="max-w-xs truncate py-2.5 pr-3 font-mono text-[10px] text-slate-500">{{ job.watermark_stamp_text }}</td>
                                    <td class="py-2.5">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a
                                                v-if="job.has_spool_preview"
                                                :href="`/print-scan/jobs/${job.id}/preview`"
                                                target="_blank"
                                                class="inline-flex items-center gap-1 rounded border border-slate-300 px-2 py-1 font-semibold text-slate-700 hover:bg-slate-50"
                                            >
                                                <Eye class="h-3 w-3" /> Spool Preview
                                            </a>
                                            <template v-if="job.status === 'held'">
                                                <input
                                                    v-model="pinInputs[job.id]"
                                                    type="password"
                                                    placeholder="6-digit PIN"
                                                    class="w-24 rounded border border-slate-300 px-2 py-1 font-mono text-xs"
                                                />
                                                <button type="button" @click="submitReleasePin(job.id)" class="rounded bg-slate-900 px-2.5 py-1 font-semibold text-white hover:bg-slate-700">
                                                    Release PIN
                                                </button>
                                            </template>
                                            <span v-else-if="job.cups_job_id" class="font-mono text-[11px] text-emerald-700">{{ job.cups_job_id }}</span>
                                            <span v-else-if="job.status === 'locked'" class="inline-flex items-center gap-1 font-semibold text-red-700">
                                                <ShieldAlert class="h-3.5 w-3.5" /> Locked
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Register New Printer (Admins/Records) -->
                <div v-if="canManageDevices" class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs">
                    <h3 class="mb-3 font-bold text-slate-900">Register CUPS / IPPS LAN Printer (with Classification Ceiling)</h3>
                    <form @submit.prevent="submitPrinter" class="grid grid-cols-1 gap-3 md:grid-cols-6">
                        <input v-model="printerForm.name" type="text" placeholder="Printer Name" required class="rounded-lg border border-slate-300 px-2.5 py-1.5" />
                        <input v-model="printerForm.cups_queue_name" type="text" placeholder="CUPS Queue" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono" />
                        <input v-model="printerForm.ipps_uri" type="text" placeholder="http://cups:631/printers/..." required class="rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono" />
                        <select v-model="printerForm.driver" class="rounded-lg border border-slate-300 px-2 py-1.5">
                            <option value="local_spool">Local Spool</option>
                            <option value="cups_ipps">CUPS IPPS</option>
                        </select>
                        <select v-model.number="printerForm.max_classification_rank" class="rounded-lg border border-slate-300 px-2 py-1.5">
                            <option :value="0">Max L0 (Public)</option>
                            <option :value="1">Max L1 (Internal/Restricted)</option>
                            <option :value="2">Max L2 (Confidential)</option>
                            <option :value="3">Max L3 (Secret)</option>
                            <option :value="4">Max L4 (Top Secret)</option>
                        </select>
                        <button type="submit" :disabled="printerForm.processing" class="rounded-lg bg-slate-900 px-3 py-1.5 font-semibold text-white">Add Printer</button>
                    </form>
                </div>
            </div>

            <!-- TAB 2: SCAN CAPTURE, BARCODE SEPARATOR GENERATOR & LAN SOURCES -->
            <div v-if="activeTab === 'scan'" class="space-y-6">
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <!-- Barcode Separator Sheet Generator -->
                    <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs">
                        <h2 class="mb-3 flex items-center gap-2 font-bold text-slate-900">
                            <QrCode class="h-4 w-4 text-sky-700" />
                            <span>Print Barcode Separator Sheet (QR + 2D Optical Split Matrix)</span>
                        </h2>
                        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Document Type</label>
                                <select v-model="sepDocType" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5">
                                    <option value="invoice">Invoice (Auto-Triggers IDP)</option>
                                    <option value="contract">Contract</option>
                                    <option value="letter">Official Correspondence / Letter</option>
                                    <option value="memo">Command Memo</option>
                                    <option value="id_document">National ID / KYC Pack</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Title Prefix</label>
                                <input v-model="sepTitle" type="text" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5" />
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Target Cabinet</label>
                                <select v-model="sepCabinet" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5">
                                    <option v-for="c in cabinets" :key="c.id" :value="c.name">{{ c.name }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Target Classification</label>
                                <select v-model="sepClassification" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5">
                                    <option v-for="l in allowedClearances" :key="l.id" :value="l.name">{{ l.name }}</option>
                                </select>
                            </div>
                        </div>
                        <a
                            :href="separatorDownloadUrl()"
                            class="mt-4 inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700"
                        >
                            <Download class="h-3.5 w-3.5" /> Download Barcode Separator PDF
                        </a>
                    </div>

                    <!-- Batch Scan Upload (Auto-Deskew + Barcode Split + OCR) -->
                    <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs">
                        <h2 class="mb-3 flex items-center gap-2 font-bold text-slate-900">
                            <FileScan class="h-4 w-4 text-emerald-700" />
                            <span>Batch Scan Feeder Upload (Auto-Split + Deskew + OCR + IDP)</span>
                        </h2>
                        <form @submit.prevent="submitScanBatch" class="space-y-3">
                            <div class="grid grid-cols-2 gap-2">
                                <select v-model="scanBatchForm.cabinet_id" class="rounded-lg border border-slate-300 px-2 py-1.5">
                                    <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                                </select>
                                <select v-model="scanBatchForm.classification_level_id" class="rounded-lg border border-slate-300 px-2 py-1.5">
                                    <option v-for="l in allowedClearances" :key="l.id" :value="l.id">{{ l.name }}</option>
                                </select>
                            </div>
                            <input type="file" accept=".pdf,.png,.jpg,.jpeg,.tif,.tiff" @change="onScanFileSelect" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5" />
                            <div class="flex flex-wrap gap-4 text-[11px] font-medium text-slate-700">
                                <label class="inline-flex items-center gap-1.5"><input v-model="scanBatchForm.auto_split_barcode" type="checkbox" /> Barcode Separator Split</label>
                                <label class="inline-flex items-center gap-1.5"><input v-model="scanBatchForm.auto_deskew" type="checkbox" /> Auto-Deskew &amp; Border Crop</label>
                                <label class="inline-flex items-center gap-1.5"><input v-model="scanBatchForm.auto_idp" type="checkbox" /> Auto-IDP Extraction</label>
                            </div>
                            <button type="submit" :disabled="!scanBatchForm.file || scanBatchForm.processing" class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-600 disabled:opacity-50">
                                <UploadCloud class="h-3.5 w-3.5" /> Process Scan Batch
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Configured LAN Scan Sources (eSCL, Hot Folders, Local Mail-In) -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs">
                    <h2 class="mb-3 flex items-center gap-2 font-bold text-slate-900">
                        <HardDrive class="h-4 w-4 text-slate-700" />
                        <span>Configured LAN Scanners, Hot Folders &amp; Mail-In Dropboxes</span>
                    </h2>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div v-for="src in scanSources" :key="src.id" class="rounded-lg border border-slate-200 p-3">
                            <div class="flex items-center justify-between">
                                <span class="font-bold text-slate-900">{{ src.name }}</span>
                                <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] uppercase">{{ src.source_type }}</span>
                            </div>
                            <p class="mt-1 font-mono text-[11px] text-slate-500">{{ src.endpoint_or_path }}</p>
                            <div class="mt-2 flex items-center justify-between">
                                <span class="text-[11px] text-slate-500">{{ src.cabinet_name ?? 'Default Cabinet' }}</span>
                                <button type="button" @click="pollSource(src.id)" class="rounded bg-slate-900 px-2.5 py-1 font-semibold text-white hover:bg-slate-700">Poll Now</button>
                            </div>
                        </div>
                    </div>

                    <form v-if="canManageDevices" @submit.prevent="submitScanSource" class="mt-4 grid grid-cols-1 gap-2 border-t border-slate-200 pt-3 md:grid-cols-5">
                        <input v-model="sourceForm.name" type="text" placeholder="Source Name" required class="rounded border border-slate-300 px-2 py-1.5" />
                        <select v-model="sourceForm.source_type" class="rounded border border-slate-300 px-2 py-1.5">
                            <option value="hot_folder">Hot Folder</option>
                            <option value="mail_in">Local Mail-In (.eml)</option>
                            <option value="escl_airscan">eSCL / AirScan MFP</option>
                            <option value="sane">SANE Scanner</option>
                        </select>
                        <input v-model="sourceForm.endpoint_or_path" type="text" placeholder="Folder Name or http://scanner" required class="rounded border border-slate-300 px-2 py-1.5 font-mono" />
                        <select v-model="sourceForm.target_cabinet_id" class="rounded border border-slate-300 px-2 py-1.5">
                            <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <button type="submit" class="rounded bg-sky-700 px-3 py-1.5 font-semibold text-white">Add Source</button>
                    </form>
                </div>

                <!-- Scan Batch Ledger -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs">
                    <h3 class="mb-3 font-bold uppercase tracking-wider text-slate-700">Processed Scan Batches</h3>
                    <div v-if="scanBatches.length === 0" class="text-slate-500">No scan batches processed yet.</div>
                    <div v-else class="space-y-2">
                        <div v-for="b in scanBatches" :key="b.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-3">
                            <div>
                                <div class="font-semibold text-slate-900">{{ b.original_filename }} <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px]">{{ b.source_channel }}</span></div>
                                <div class="text-[11px] text-slate-500">
                                    {{ b.cabinet_name }} · {{ b.page_count }} input page(s) · {{ b.report.separators_found ?? 0 }} barcode separator(s) -> <span class="font-bold text-emerald-700">{{ b.split_documents_count }} document(s) catalogued</span>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <Link
                                    v-for="docId in b.report.created_doc_ids ?? []"
                                    :key="docId"
                                    :href="`/documents/${docId}`"
                                    class="rounded border border-sky-200 bg-sky-50 px-2 py-1 font-mono text-[11px] font-semibold text-sky-800 hover:bg-sky-100"
                                >
                                    Open {{ docId.slice(0, 8) }}
                                </Link>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: PWA OFFLINE MOBILE CAMERA QUEUE (INDEXEDDB) -->
            <div v-if="activeTab === 'pwa'" class="space-y-6">
                <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2 font-bold text-slate-900">
                            <Smartphone class="h-4 w-4 text-sky-700" />
                            <span>Mobile PWA Offline Camera Capture (Service Worker + IndexedDB Sync Queue)</span>
                        </div>
                        <label class="inline-flex items-center gap-2 rounded-lg border border-amber-300 bg-amber-50 px-3 py-1 font-semibold text-amber-900">
                            <WifiOff class="h-3.5 w-3.5" />
                            <input v-model="pwaOfflineSimulated" type="checkbox" />
                            <span>Simulate Offline / Load-Shedding Mode</span>
                        </label>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div>
                            <label class="mb-1 block font-semibold text-slate-700">Title Prefix</label>
                            <input v-model="pwaCaptureTitle" type="text" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5" />
                        </div>
                        <div>
                            <label class="mb-1 block font-semibold text-slate-700">Target Cabinet</label>
                            <select v-model="pwaCabinetId" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5">
                                <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-semibold text-slate-700">Clearance</label>
                            <select v-model="pwaLevelId" class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5">
                                <option v-for="l in allowedClearances" :key="l.id" :value="l.id">{{ l.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap items-center gap-3">
                        <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-slate-900 px-4 py-2 font-semibold text-white hover:bg-slate-700">
                            <Camera class="h-4 w-4" />
                            <span>Capture Document Photo / Scan to IndexedDB</span>
                            <input type="file" accept="image/*,application/pdf" capture="environment" class="hidden" @change="onPwaCameraCapture" />
                        </label>
                        <button
                            type="button"
                            :disabled="offlineQueue.length === 0 || pwaSyncBusy"
                            @click="syncOfflineQueue"
                            class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-600 disabled:opacity-50"
                        >
                            <UploadCloud class="h-4 w-4" />
                            <span>Sync {{ offlineQueue.length }} Queued Scan(s) to Vault</span>
                        </button>
                    </div>
                    <p v-if="pwaStatusMsg" class="mt-3 rounded border border-sky-200 bg-sky-50 p-2 font-medium text-sky-900">{{ pwaStatusMsg }}</p>

                    <div v-if="offlineQueue.length > 0" class="mt-4 space-y-2">
                        <div v-for="q in offlineQueue" :key="q.id" class="flex items-center justify-between rounded border border-slate-200 p-2.5">
                            <div>
                                <span class="font-semibold text-slate-900">{{ q.title }}</span>
                                <span class="ml-2 font-mono text-[11px] text-slate-500">{{ q.filename }} ({{ Math.round(q.size_bytes / 1024) }} KB)</span>
                            </div>
                            <span class="rounded bg-amber-100 px-2 py-0.5 font-mono text-[10px] font-bold text-amber-900 uppercase">Queued in IndexedDB</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 4: SENTINEL LOCAL WORKSTATION AGENT -->
            <div v-if="activeTab === 'agent'" class="space-y-6">
                <div class="rounded-xl border border-slate-200 bg-white p-5 text-xs shadow-xs">
                    <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2 font-bold text-slate-900">
                            <Laptop class="h-4 w-4 text-sky-700" />
                            <span>Sentinel Local Workstation Agent (`127.0.0.1` TWAIN / WIA Scanner &amp; PKCS#11 Bridge)</span>
                        </div>
                        <a href="/print-scan/agents/download-script" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-slate-50 px-3 py-1.5 font-semibold text-slate-800 hover:bg-slate-100">
                            <Download class="h-3.5 w-3.5" /> Download `sentinel-local-agent.py`
                        </a>
                    </div>
                    <form @submit.prevent="submitAgentPairInit" class="flex flex-wrap items-center gap-2">
                        <input v-model="agentForm.workstation_name" type="text" placeholder="Workstation Hostname" required class="w-64 rounded-lg border border-slate-300 px-3 py-1.5" />
                        <button type="submit" :disabled="agentForm.processing" class="rounded-lg bg-sky-700 px-4 py-1.5 font-semibold text-white hover:bg-sky-600">
                            Generate One-Time Pairing Code
                        </button>
                    </form>

                    <div class="mt-4 space-y-2">
                        <div v-for="a in localAgents" :key="a.id" class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-slate-200 p-3">
                            <div>
                                <div class="font-bold text-slate-900">{{ a.workstation_name }} <span class="ml-1 font-mono text-[10px] text-slate-500">v{{ a.agent_version }} · {{ a.paired_ip ?? 'unpaired' }}</span></div>
                                <div class="text-[11px] text-slate-500">ID: <span class="font-mono">{{ a.id }}</span> · Capabilities: <span class="font-mono">{{ JSON.stringify(a.capabilities) }}</span></div>
                            </div>
                            <div class="flex items-center gap-2">
                                <span
                                    class="rounded px-2 py-0.5 font-mono text-[10px] font-bold uppercase"
                                    :class="a.status === 'paired' ? 'bg-emerald-100 text-emerald-900' : 'bg-slate-100 text-slate-700'"
                                >
                                    {{ a.status }}
                                </span>
                                <button v-if="a.status !== 'revoked'" type="button" @click="revokeAgent(a.id)" class="rounded border border-red-200 bg-red-50 px-2.5 py-1 font-semibold text-red-700">
                                    Revoke
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
