<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Archive,
    ArrowLeft,
    Camera,
    CheckCircle2,
    CloudUpload,
    Download,
    FileCheck2,
    FileText,
    FolderOpen,
    Loader2,
    Receipt,
    RefreshCw,
    Search,
    Send,
    ShieldCheck,
    Smartphone,
    Sparkles,
    Trash2,
    Wifi,
    WifiOff,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import {
    enqueueOfflineScan,
    listOfflineScans,
    removeOfflineScan,
    type OfflineQueuedScan,
} from '@/lib/offlineScanQueue';

interface CabinetItem {
    id: string;
    name: string;
}

interface ClearanceItem {
    id: string;
    rank: number;
    code: string;
    name: string;
}

interface VendorItem {
    id: string;
    code: string;
    name: string;
    currency: string;
}

interface PoItem {
    id: string;
    po_number: string;
    vendor_id: string;
    vendor_name: string;
    currency: string;
    total_cents: number;
}

interface ErpTargetItem {
    target_type: 'connection' | 'bridge';
    target_id: string;
    name: string;
    system_type: string;
    mode: string;
    status: string;
}

interface MobileDocItem {
    id: string;
    doc_number: string;
    title: string;
    status: string;
    is_worm: boolean;
    cabinet_id: string;
    cabinet_name: string;
    classification_name: string;
    classification_rank: number;
    idp_doc_type: string | null;
    mobile_erp_processing: {
        ap_invoice_id?: string;
        invoice_number?: string;
        vendor_name?: string;
        total_cents?: number;
        currency?: string;
        ap_status?: string;
        erp_target_name?: string;
        erp_sync_status?: string;
        processed_at?: string;
    } | null;
    created_at: string | null;
}

interface CaptureResultPayload {
    batch_id: string;
    document: MobileDocItem;
    extraction: {
        id: string;
        detected_doc_type: string;
        overall_confidence: number;
        review_status: string;
        fields: Record<string, { label: string; value: string; confidence: number }>;
    } | null;
    is_invoice: boolean;
    suggested_invoice: {
        vendor_id: string | null;
        vendor_name: string;
        purchase_order_id: string | null;
        po_number: string | null;
        invoice_number: string;
        invoice_date: string;
        currency: string;
        subtotal_cents: number;
        vat_cents: number;
        total_cents: number;
        zimra_tin: string | null;
        description: string;
    } | null;
    erp_targets: ErpTargetItem[];
}

interface ErpProcessResult {
    document: MobileDocItem;
    ap_invoice: {
        id: string;
        invoice_number: string;
        vendor_name: string;
        currency: string;
        subtotal_cents: number;
        vat_cents: number;
        total_cents: number;
        status: string;
    };
    erp_result: {
        target_type: string;
        target_name: string;
        status: string;
        message: string;
    };
}

interface BeforeInstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

const props = defineProps<{
    cabinets: CabinetItem[];
    allowedClearances: ClearanceItem[];
    vendors: VendorItem[];
    purchaseOrders: PoItem[];
    erpTargets: ErpTargetItem[];
    recentDocuments: MobileDocItem[];
}>();

const activeTab = ref<'snap' | 'queue' | 'docs'>('snap');
const isOnline = ref(typeof navigator !== 'undefined' ? navigator.onLine : true);
const simulateOffline = ref(false);
const effectivelyOffline = computed(() => !isOnline.value || simulateOffline.value);

// PWA Install Prompt
const installPrompt = ref<BeforeInstallPromptEvent | null>(null);
const pwaInstalled = ref(false);

// Step 1: Camera Snap State
const selectedFile = ref<File | null>(null);
const previewUrl = ref<string | null>(null);
const captureTitle = ref('');
const cabinetId = ref(props.cabinets[0]?.id ?? '');
const clearanceLevelId = ref(props.allowedClearances[0]?.id ?? '');
const autoDeskew = ref(true);
const forceInvoice = ref(false);
const captureBusy = ref(false);
const captureError = ref('');

// Step 2: AI Detection & Invoice-to-ERP Review State
const captureResult = ref<CaptureResultPayload | null>(null);
const erpProcessResult = ref<ErpProcessResult | null>(null);
const erpBusy = ref(false);
const erpError = ref('');

const invoiceForm = ref({
    vendor_id: '',
    vendor_name: '',
    purchase_order_id: '',
    invoice_number: '',
    invoice_date: new Date().toISOString().slice(0, 10),
    currency: 'USD',
    subtotal: '0.00',
    vat: '0.00',
    total: '0.00',
    description: '',
    match_mode: '2_way',
    auto_approve: true,
    override_reason: '',
    erp_selection: 'none',
    archive_document: true,
    apply_worm_lock: false,
});

// Quick non-invoice manage state
const quickTitle = ref('');
const quickCabinetId = ref('');
const quickBusy = ref(false);
const quickMsg = ref('');

// Tab 2: Offline IndexedDB Queue
const offlineQueue = ref<OfflineQueuedScan[]>([]);
const queueSyncBusy = ref(false);
const queueStatusMsg = ref('');

// Tab 3: Recent Mobile Documents
const docsSearch = ref('');
const localDocs = ref<MobileDocItem[]>([...props.recentDocuments]);

const filteredDocs = computed(() => {
    const q = docsSearch.value.trim().toLowerCase();
    if (!q) {
        return localDocs.value;
    }
    return localDocs.value.filter(
        (d) =>
            d.title.toLowerCase().includes(q) ||
            d.doc_number.toLowerCase().includes(q) ||
            d.cabinet_name.toLowerCase().includes(q) ||
            (d.mobile_erp_processing?.invoice_number ?? '').toLowerCase().includes(q)
    );
});

function csrfToken(): string {
    const meta = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]');
    return meta?.content ?? '';
}

function defaultErpSelection(targets: ErpTargetItem[]): string {
    if (targets.length > 0) {
        return `${targets[0].target_type}:${targets[0].target_id}`;
    }
    return 'none';
}

function moneyToCents(val: string): number {
    const parsed = Number.parseFloat(val.replace(/[^0-9.]/g, ''));
    if (Number.isNaN(parsed) || parsed < 0) {
        return 0;
    }
    return Math.round(parsed * 100);
}

function centsToMoney(cents: number): string {
    return (cents / 100).toFixed(2);
}

function recalcTotal(): void {
    const totalCents = moneyToCents(invoiceForm.value.subtotal) + moneyToCents(invoiceForm.value.vat);
    invoiceForm.value.total = centsToMoney(totalCents);
}

function handleOnline(): void {
    isOnline.value = true;
}

function handleOffline(): void {
    isOnline.value = false;
}

function handleBeforeInstallPrompt(e: Event): void {
    e.preventDefault();
    installPrompt.value = e as BeforeInstallPromptEvent;
}

async function triggerInstallPwa(): Promise<void> {
    if (!installPrompt.value) {
        return;
    }
    await installPrompt.value.prompt();
    const choice = await installPrompt.value.userChoice;
    if (choice.outcome === 'accepted') {
        pwaInstalled.value = true;
    }
    installPrompt.value = null;
}

async function refreshOfflineQueue(): Promise<void> {
    try {
        offlineQueue.value = await listOfflineScans();
    } catch {
        offlineQueue.value = [];
    }
}

onMounted(() => {
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    window.addEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
    refreshOfflineQueue();
});

onUnmounted(() => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
    window.removeEventListener('beforeinstallprompt', handleBeforeInstallPrompt);
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
    }
});

function onCameraFilePicked(event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    if (!file) {
        return;
    }

    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }

    selectedFile.value = file;
    captureError.value = '';
    erpProcessResult.value = null;
    captureResult.value = null;

    if (file.type.startsWith('image/')) {
        previewUrl.value = URL.createObjectURL(file);
    }

    if (!captureTitle.value.trim()) {
        captureTitle.value = file.name.replace(/\.[^/.]+$/, '');
    }
}

function resetCaptureFlow(): void {
    if (previewUrl.value) {
        URL.revokeObjectURL(previewUrl.value);
        previewUrl.value = null;
    }
    selectedFile.value = null;
    captureTitle.value = '';
    captureResult.value = null;
    erpProcessResult.value = null;
    captureError.value = '';
    erpError.value = '';
    quickMsg.value = '';
}

function hydrateReviewFromCapture(res: CaptureResultPayload): void {
    captureResult.value = res;
    quickTitle.value = res.document.title;
    quickCabinetId.value = res.document.cabinet_id;

    // Prepend into local docs list
    localDocs.value = [res.document, ...localDocs.value.filter((d) => d.id !== res.document.id)];

    if (res.is_invoice && res.suggested_invoice) {
        const inv = res.suggested_invoice;
        invoiceForm.value = {
            vendor_id: inv.vendor_id ?? '',
            vendor_name: inv.vendor_name,
            purchase_order_id: inv.purchase_order_id ?? '',
            invoice_number: inv.invoice_number,
            invoice_date: inv.invoice_date,
            currency: inv.currency,
            subtotal: centsToMoney(inv.subtotal_cents),
            vat: centsToMoney(inv.vat_cents),
            total: centsToMoney(inv.total_cents),
            description: inv.description,
            match_mode: inv.purchase_order_id ? '3_way' : '2_way',
            auto_approve: true,
            override_reason: '',
            erp_selection: defaultErpSelection(res.erp_targets),
            archive_document: true,
            apply_worm_lock: false,
        };
    }
}

async function submitMobileCapture(): Promise<void> {
    if (!selectedFile.value) {
        captureError.value = 'Take a photo or choose a file first.';
        return;
    }

    captureError.value = '';
    captureBusy.value = true;

    try {
        if (effectivelyOffline.value) {
            await enqueueOfflineScan({
                id: `${Date.now()}-${Math.random().toString(36).slice(2, 7)}`,
                filename: selectedFile.value.name || 'mobile-capture.jpg',
                title: captureTitle.value.trim() || 'Mobile Field Capture',
                cabinet_id: cabinetId.value,
                classification_level_id: clearanceLevelId.value,
                auto_split_barcode: false,
                auto_deskew: autoDeskew.value,
                force_invoice: forceInvoice.value,
                blob: selectedFile.value,
                queued_at: new Date().toISOString(),
            });
            await refreshOfflineQueue();
            queueStatusMsg.value = 'Saved safely in device offline vault (IndexedDB). Will process & detect invoices once online.';
            resetCaptureFlow();
            activeTab.value = 'queue';
            return;
        }

        const form = new FormData();
        form.append('file', selectedFile.value, selectedFile.value.name || 'mobile-capture.jpg');
        form.append('cabinet_id', cabinetId.value);
        form.append('classification_level_id', clearanceLevelId.value);
        form.append('title', captureTitle.value.trim());
        form.append('auto_deskew', autoDeskew.value ? '1' : '0');
        form.append('force_invoice', forceInvoice.value ? '1' : '0');

        const resp = await fetch('/mobile/capture', {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: form,
        });

        const data = await resp.json();
        if (!resp.ok) {
            captureError.value = data.message ?? 'Capture upload failed.';
            return;
        }

        hydrateReviewFromCapture(data as CaptureResultPayload);
    } catch (e) {
        captureError.value = e instanceof Error ? e.message : 'Network error during capture.';
    } finally {
        captureBusy.value = false;
    }
}

function switchCaptureToInvoiceMode(): void {
    if (!captureResult.value) {
        return;
    }
    const doc = captureResult.value.document;
    captureResult.value = {
        ...captureResult.value,
        is_invoice: true,
        suggested_invoice: captureResult.value.suggested_invoice ?? {
            vendor_id: props.vendors[0]?.id ?? null,
            vendor_name: props.vendors[0]?.name ?? 'Supplier',
            purchase_order_id: null,
            po_number: null,
            invoice_number: `INV-${new Date().toISOString().slice(0, 10).replace(/-/g, '')}`,
            invoice_date: new Date().toISOString().slice(0, 10),
            currency: 'USD',
            subtotal_cents: 0,
            vat_cents: 0,
            total_cents: 0,
            zimra_tin: null,
            description: `Mobile Captured Invoice — ${doc.title}`,
        },
    };
    hydrateReviewFromCapture(captureResult.value);
}

async function submitInvoiceToErpAndArchive(): Promise<void> {
    if (!captureResult.value) {
        return;
    }
    erpBusy.value = true;
    erpError.value = '';

    try {
        let erpTargetType = 'none';
        let erpTargetId: string | null = null;

        if (invoiceForm.value.erp_selection.includes(':')) {
            const [t, id] = invoiceForm.value.erp_selection.split(':');
            erpTargetType = t;
            erpTargetId = id;
        }

        const resp = await fetch(`/mobile/documents/${captureResult.value.document.id}/process-invoice`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                vendor_id: invoiceForm.value.vendor_id || null,
                vendor_name: invoiceForm.value.vendor_name,
                purchase_order_id: invoiceForm.value.purchase_order_id || null,
                invoice_number: invoiceForm.value.invoice_number,
                invoice_date: invoiceForm.value.invoice_date,
                currency: invoiceForm.value.currency,
                subtotal_cents: moneyToCents(invoiceForm.value.subtotal),
                vat_cents: moneyToCents(invoiceForm.value.vat),
                total_cents: moneyToCents(invoiceForm.value.total),
                description: invoiceForm.value.description,
                match_mode: invoiceForm.value.match_mode,
                auto_approve: invoiceForm.value.auto_approve,
                override_reason: invoiceForm.value.override_reason || null,
                erp_target_type: erpTargetType,
                erp_target_id: erpTargetId,
                archive_document: invoiceForm.value.archive_document,
                apply_worm_lock: invoiceForm.value.apply_worm_lock,
            }),
        });

        const data = await resp.json();
        if (!resp.ok) {
            erpError.value = data.message ?? 'Invoice processing failed.';
            return;
        }

        erpProcessResult.value = data as ErpProcessResult;
        const updatedDoc = erpProcessResult.value.document;
        localDocs.value = [updatedDoc, ...localDocs.value.filter((d) => d.id !== updatedDoc.id)];
    } catch (e) {
        erpError.value = e instanceof Error ? e.message : 'Network error pushing invoice to ERP.';
    } finally {
        erpBusy.value = false;
    }
}

async function quickManageDocument(docId: string, status: 'active' | 'archived'): Promise<void> {
    quickBusy.value = true;
    quickMsg.value = '';
    try {
        const resp = await fetch(`/mobile/documents/${docId}/manage`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            body: JSON.stringify({
                title: quickTitle.value || undefined,
                cabinet_id: quickCabinetId.value || undefined,
                status,
            }),
        });
        const data = await resp.json();
        if (resp.ok && data.document) {
            const updatedDoc = data.document as MobileDocItem;
            localDocs.value = localDocs.value.map((d) => (d.id === updatedDoc.id ? updatedDoc : d));
            if (captureResult.value && captureResult.value.document.id === updatedDoc.id) {
                captureResult.value.document = updatedDoc;
            }
            quickMsg.value = status === 'archived' ? 'Document archived in vault.' : 'Document updated.';
        }
    } finally {
        quickBusy.value = false;
    }
}

async function syncOfflineQueueNow(): Promise<void> {
    if (effectivelyOffline.value) {
        queueStatusMsg.value = 'Still offline — reconnect to sync queued photos.';
        return;
    }

    queueSyncBusy.value = true;
    queueStatusMsg.value = '';
    let syncedCount = 0;
    let lastInvoiceCapture: CaptureResultPayload | null = null;

    try {
        const items = await listOfflineScans();
        for (const item of items) {
            const form = new FormData();
            form.append('file', item.blob, item.filename);
            form.append('cabinet_id', item.cabinet_id);
            form.append('classification_level_id', item.classification_level_id);
            form.append('title', item.title);
            form.append('auto_deskew', item.auto_deskew ? '1' : '0');
            form.append('force_invoice', item.force_invoice ? '1' : '0');

            const resp = await fetch('/mobile/capture', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: form,
            });

            if (resp.ok) {
                const payload = (await resp.json()) as CaptureResultPayload;
                await removeOfflineScan(item.id);
                syncedCount++;
                localDocs.value = [payload.document, ...localDocs.value.filter((d) => d.id !== payload.document.id)];
                if (payload.is_invoice) {
                    lastInvoiceCapture = payload;
                }
            }
        }
        await refreshOfflineQueue();
        queueStatusMsg.value = `Synced ${syncedCount} offline capture(s) into BetterDoc AI & Vault.`;

        if (lastInvoiceCapture) {
            hydrateReviewFromCapture(lastInvoiceCapture);
            activeTab.value = 'snap';
        }
    } finally {
        queueSyncBusy.value = false;
    }
}

async function deleteQueuedItem(id: string): Promise<void> {
    await removeOfflineScan(id);
    await refreshOfflineQueue();
}

function startInvoiceForExistingDoc(doc: MobileDocItem): void {
    captureResult.value = {
        batch_id: 'existing',
        document: doc,
        extraction: null,
        is_invoice: true,
        suggested_invoice: {
            vendor_id: props.vendors[0]?.id ?? null,
            vendor_name: props.vendors[0]?.name ?? 'Supplier',
            purchase_order_id: null,
            po_number: null,
            invoice_number: `INV-${doc.doc_number.replace(/[^A-Z0-9]/gi, '').slice(-8)}`,
            invoice_date: new Date().toISOString().slice(0, 10),
            currency: 'USD',
            subtotal_cents: 0,
            vat_cents: 0,
            total_cents: 0,
            zimra_tin: null,
            description: `Mobile Invoice — ${doc.title}`,
        },
        erp_targets: props.erpTargets,
    };
    erpProcessResult.value = null;
    hydrateReviewFromCapture(captureResult.value);
    activeTab.value = 'snap';
}
</script>

<template>
    <Head title="Mobile Capture & ERP (PWA)" />

    <AppLayout>
        <div class="mx-auto max-w-2xl pb-24">
            <!-- Mobile PWA Header -->
            <div class="mb-4 flex items-center justify-between gap-3 rounded-2xl bg-slate-900 p-4 text-white shadow-sm">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-sky-500/20 text-sky-400">
                        <Smartphone class="h-5 w-5" />
                    </div>
                    <div>
                        <h1 class="text-base font-bold leading-tight">BetterDoc Mobile Snap</h1>
                        <p class="text-[11px] text-slate-300">Camera → AI Invoice Detection → Accounting ERP → Archive</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        @click="simulateOffline = !simulateOffline"
                        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold transition"
                        :class="effectivelyOffline ? 'bg-amber-500/20 text-amber-300' : 'bg-emerald-500/20 text-emerald-300'"
                        title="Toggle Offline Mode Test"
                    >
                        <WifiOff v-if="effectivelyOffline" class="h-3.5 w-3.5" />
                        <Wifi v-else class="h-3.5 w-3.5" />
                        <span>{{ effectivelyOffline ? 'Offline' : 'Online' }}</span>
                    </button>
                </div>
            </div>

            <!-- Install PWA Banner -->
            <div
                v-if="installPrompt && !pwaInstalled"
                class="mb-4 flex items-center justify-between gap-3 rounded-xl border border-sky-200 bg-sky-50 px-4 py-3 text-xs text-sky-900"
            >
                <div class="flex items-center gap-2">
                    <Download class="h-4 w-4 shrink-0 text-sky-600" />
                    <span>Install <strong>BetterDoc Mobile</strong> on your Home Screen for 1-tap camera capture.</span>
                </div>
                <button
                    type="button"
                    @click="triggerInstallPwa"
                    class="shrink-0 rounded-lg bg-sky-600 px-3 py-1.5 font-semibold text-white hover:bg-sky-700"
                >
                    Install App
                </button>
            </div>

            <!-- TAB 1: SNAP & PROCESS -->
            <div v-if="activeTab === 'snap'" class="space-y-4">
                <!-- STEP 3: ERP & ARCHIVE COMPLETE -->
                <div
                    v-if="erpProcessResult"
                    class="rounded-2xl border border-emerald-200 bg-white p-5 shadow-xs"
                >
                    <div class="flex items-start gap-3">
                        <div class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                            <CheckCircle2 class="h-6 w-6" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <h2 class="text-base font-bold text-slate-900">Invoice Processed &amp; Archived</h2>
                            <p class="mt-0.5 text-xs text-slate-600">
                                {{ erpProcessResult.erp_result.message }}
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-2 gap-2.5 rounded-xl bg-slate-50 p-3 text-xs">
                        <div>
                            <p class="text-[10px] font-semibold uppercase text-slate-500">Vendor</p>
                            <p class="font-bold text-slate-900">{{ erpProcessResult.ap_invoice.vendor_name }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase text-slate-500">Invoice #</p>
                            <p class="font-mono font-bold text-slate-900">{{ erpProcessResult.ap_invoice.invoice_number }}</p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase text-slate-500">Total</p>
                            <p class="font-bold text-slate-900">
                                {{ erpProcessResult.ap_invoice.currency }} {{ centsToMoney(erpProcessResult.ap_invoice.total_cents) }}
                            </p>
                        </div>
                        <div>
                            <p class="text-[10px] font-semibold uppercase text-slate-500">Vault Status</p>
                            <p class="font-bold uppercase text-emerald-700">
                                {{ erpProcessResult.document.status }}
                                <span v-if="erpProcessResult.document.is_worm"> · WORM</span>
                            </p>
                        </div>
                        <div class="col-span-2 border-t border-slate-200 pt-2">
                            <p class="text-[10px] font-semibold uppercase text-slate-500">Accounting ERP Target</p>
                            <p class="font-semibold text-sky-800">
                                {{ erpProcessResult.erp_result.target_name }} —
                                <span class="font-mono uppercase">{{ erpProcessResult.erp_result.status }}</span>
                            </p>
                        </div>
                    </div>

                    <div class="mt-4 flex flex-wrap gap-2">
                        <button
                            type="button"
                            @click="resetCaptureFlow"
                            class="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-sky-600 px-4 py-2.5 text-xs font-bold text-white hover:bg-sky-700"
                        >
                            <Camera class="h-4 w-4" /> Snap Next Document
                        </button>
                        <Link
                            :href="`/documents/${erpProcessResult.document.id}`"
                            class="inline-flex items-center justify-center gap-1.5 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            <FileText class="h-4 w-4" /> Open Vault Doc
                        </Link>
                    </div>
                </div>

                <!-- STEP 2: AI DETECTED RESULT (INVOICE -> ERP OR GENERAL DOC) -->
                <div v-else-if="captureResult" class="space-y-4">
                    <div class="flex items-center justify-between">
                        <button
                            type="button"
                            @click="resetCaptureFlow"
                            class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-600 hover:text-slate-900"
                        >
                            <ArrowLeft class="h-4 w-4" /> Snap Another Photo
                        </button>
                        <span class="rounded-full bg-slate-900 px-2.5 py-1 font-mono text-[10px] font-semibold text-white">
                            {{ captureResult.document.doc_number }}
                        </span>
                    </div>

                    <!-- AI Classification Banner -->
                    <div
                        class="rounded-2xl border p-4"
                        :class="captureResult.is_invoice ? 'border-emerald-200 bg-emerald-50/70' : 'border-sky-200 bg-sky-50/70'"
                    >
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <Sparkles class="h-4 w-4" :class="captureResult.is_invoice ? 'text-emerald-700' : 'text-sky-700'" />
                                <span class="text-xs font-bold text-slate-900">
                                    {{
                                        captureResult.is_invoice
                                            ? 'Accounting Invoice Detected — Ready for ERP Sync'
                                            : `Document Classified as: ${(captureResult.extraction?.detected_doc_type ?? 'general').toUpperCase()}`
                                    }}
                                </span>
                            </div>
                            <span
                                v-if="captureResult.extraction"
                                class="rounded-full bg-white px-2 py-0.5 font-mono text-[10px] font-bold text-slate-700"
                            >
                                {{ Math.round(captureResult.extraction.overall_confidence * 100) }}% AI
                            </span>
                        </div>
                        <p class="mt-1 text-[11px] text-slate-600">
                            {{
                                captureResult.is_invoice
                                    ? 'Confirm the extracted figures below, pick your Accounting ERP (Xero, NetSuite, or Sage Pastel LAN Bridge), and tap Send & Archive.'
                                    : 'Encrypted and catalogued in your Vault. You can rename/archive it below or switch it to Invoice-to-ERP processing.'
                            }}
                        </p>
                    </div>

                    <!-- INVOICE -> ERP FORM -->
                    <form
                        v-if="captureResult.is_invoice"
                        @submit.prevent="submitInvoiceToErpAndArchive"
                        class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 text-xs shadow-xs"
                    >
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Existing Vendor (Optional)</label>
                                <select
                                    v-model="invoiceForm.vendor_id"
                                    @change="
                                        () => {
                                            const v = vendors.find((x) => x.id === invoiceForm.vendor_id);
                                            if (v) {
                                                invoiceForm.vendor_name = v.name;
                                                invoiceForm.currency = v.currency;
                                            }
                                        }
                                    "
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2"
                                >
                                    <option value="">+ Auto-match / Create from name</option>
                                    <option v-for="v in vendors" :key="v.id" :value="v.id">
                                        {{ v.name }} ({{ v.code }})
                                    </option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Supplier Name</label>
                                <input
                                    v-model="invoiceForm.vendor_name"
                                    type="text"
                                    required
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Invoice Number</label>
                                <input
                                    v-model="invoiceForm.invoice_number"
                                    type="text"
                                    required
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Invoice Date</label>
                                <input
                                    v-model="invoiceForm.invoice_date"
                                    type="date"
                                    required
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Currency</label>
                                <select v-model="invoiceForm.currency" class="w-full rounded-xl border border-slate-300 px-3 py-2">
                                    <option value="USD">USD</option>
                                    <option value="ZWG">ZWG</option>
                                    <option value="ZAR">ZAR</option>
                                    <option value="EUR">EUR</option>
                                    <option value="GBP">GBP</option>
                                </select>
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Link Purchase Order (2/3-Way Match)</label>
                                <select v-model="invoiceForm.purchase_order_id" class="w-full rounded-xl border border-slate-300 px-3 py-2">
                                    <option value="">No PO (Direct Expense / Bill)</option>
                                    <option v-for="po in purchaseOrders" :key="po.id" :value="po.id">
                                        {{ po.po_number }} — {{ po.vendor_name }}
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-3 gap-2">
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Subtotal</label>
                                <input
                                    v-model="invoiceForm.subtotal"
                                    @input="recalcTotal"
                                    type="text"
                                    inputmode="decimal"
                                    required
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">VAT / Tax</label>
                                <input
                                    v-model="invoiceForm.vat"
                                    @input="recalcTotal"
                                    type="text"
                                    inputmode="decimal"
                                    required
                                    class="w-full rounded-xl border border-slate-300 px-3 py-2 font-mono"
                                />
                            </div>
                            <div>
                                <label class="mb-1 block font-semibold text-slate-700">Total Due</label>
                                <input
                                    v-model="invoiceForm.total"
                                    type="text"
                                    inputmode="decimal"
                                    required
                                    class="w-full rounded-xl border border-emerald-400 bg-emerald-50/40 px-3 py-2 font-mono font-bold text-slate-900"
                                />
                            </div>
                        </div>

                        <!-- Accounting ERP Target Selector -->
                        <div class="rounded-xl border border-sky-200 bg-sky-50/60 p-3">
                            <label class="mb-1.5 flex items-center gap-1.5 font-bold text-sky-950">
                                <Receipt class="h-4 w-4 text-sky-700" />
                                <span>Accounting ERP Destination (Before Archiving)</span>
                            </label>
                            <select
                                v-model="invoiceForm.erp_selection"
                                class="w-full rounded-xl border border-sky-300 bg-white px-3 py-2 font-semibold text-slate-900"
                            >
                                <option
                                    v-for="t in captureResult.erp_targets"
                                    :key="`${t.target_type}:${t.target_id}`"
                                    :value="`${t.target_type}:${t.target_id}`"
                                >
                                    {{ t.mode === 'lan_bridge' ? 'LAN Bridge: ' : 'Cloud ERP: ' }}{{ t.name }} [{{ t.status }}]
                                </option>
                                <option value="none">BetterDoc AP Finance Ledger Only (No External ERP)</option>
                            </select>
                            <p v-if="captureResult.erp_targets.length === 0" class="mt-1.5 text-[11px] text-slate-600">
                                No external ERP connected yet. Connect Xero, NetSuite, or a Sage Pastel LAN Bridge in
                                <Link href="/admin/integrations" class="font-semibold text-sky-700 underline">Integrations &amp; ERP</Link>.
                            </p>
                        </div>

                        <div class="flex flex-wrap gap-4 pt-1 text-[11px] font-medium text-slate-700">
                            <label class="inline-flex items-center gap-1.5">
                                <input v-model="invoiceForm.auto_approve" type="checkbox" class="rounded" />
                                Auto-Approve AP Bill
                            </label>
                            <label class="inline-flex items-center gap-1.5">
                                <input v-model="invoiceForm.archive_document" type="checkbox" class="rounded" />
                                Archive Photo After ERP Push
                            </label>
                            <label class="inline-flex items-center gap-1.5">
                                <input v-model="invoiceForm.apply_worm_lock" type="checkbox" class="rounded" />
                                Apply 7-Year WORM Lock
                            </label>
                        </div>

                        <p v-if="erpError" class="rounded-lg bg-rose-50 px-3 py-2 font-semibold text-rose-700">
                            {{ erpError }}
                        </p>

                        <button
                            type="submit"
                            :disabled="erpBusy"
                            class="flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-4 py-3 text-sm font-bold text-white shadow-sm hover:bg-emerald-700 disabled:opacity-50"
                        >
                            <Loader2 v-if="erpBusy" class="h-4 w-4 animate-spin" />
                            <Send v-else class="h-4 w-4" />
                            <span>{{ erpBusy ? 'Syncing to Accounting ERP...' : 'Post to Accounting ERP & Archive' }}</span>
                        </button>
                    </form>

                    <!-- NON-INVOICE DOCUMENT MANAGEMENT CARD -->
                    <div v-else class="space-y-3 rounded-2xl border border-slate-200 bg-white p-4 text-xs shadow-xs">
                        <div>
                            <label class="mb-1 block font-semibold text-slate-700">Document Title</label>
                            <input v-model="quickTitle" type="text" class="w-full rounded-xl border border-slate-300 px-3 py-2" />
                        </div>
                        <div>
                            <label class="mb-1 block font-semibold text-slate-700">Cabinet</label>
                            <select v-model="quickCabinetId" class="w-full rounded-xl border border-slate-300 px-3 py-2">
                                <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>

                        <p v-if="quickMsg" class="rounded-lg bg-emerald-50 px-3 py-2 font-semibold text-emerald-800">{{ quickMsg }}</p>

                        <div class="grid grid-cols-2 gap-2 pt-1">
                            <button
                                type="button"
                                :disabled="quickBusy"
                                @click="quickManageDocument(captureResult.document.id, 'active')"
                                class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-slate-900 px-3 py-2.5 font-semibold text-white hover:bg-slate-800"
                            >
                                <FileCheck2 class="h-4 w-4" /> Save in Vault
                            </button>
                            <button
                                type="button"
                                :disabled="quickBusy"
                                @click="quickManageDocument(captureResult.document.id, 'archived')"
                                class="inline-flex items-center justify-center gap-1.5 rounded-xl bg-amber-600 px-3 py-2.5 font-semibold text-white hover:bg-amber-700"
                            >
                                <Archive class="h-4 w-4" /> Archive Now
                            </button>
                        </div>

                        <div class="flex items-center justify-between border-t border-slate-200 pt-3">
                            <button
                                type="button"
                                @click="switchCaptureToInvoiceMode"
                                class="inline-flex items-center gap-1.5 font-semibold text-emerald-700 hover:underline"
                            >
                                <Receipt class="h-4 w-4" /> Actually an Invoice? Process to ERP →
                            </button>
                            <Link
                                :href="`/documents/${captureResult.document.id}`"
                                class="font-semibold text-sky-700 hover:underline"
                            >
                                Full Details
                            </Link>
                        </div>
                    </div>
                </div>

                <!-- STEP 1: CAMERA CAPTURE CARD -->
                <div v-else class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-xs">
                    <!-- Big Thumb-Friendly Camera Trigger -->
                    <label
                        class="flex cursor-pointer flex-col items-center justify-center rounded-2xl border-2 border-dashed border-sky-300 bg-sky-50/50 p-6 text-center transition hover:border-sky-500 hover:bg-sky-50"
                    >
                        <input
                            type="file"
                            accept="image/*,.pdf"
                            capture="environment"
                            @change="onCameraFilePicked"
                            class="hidden"
                        />
                        <div v-if="previewUrl" class="mb-3 w-full overflow-hidden rounded-xl border border-slate-200 bg-slate-900">
                            <img :src="previewUrl" alt="Captured preview" class="mx-auto max-h-64 object-contain" />
                        </div>
                        <div
                            v-else
                            class="mb-3 flex h-16 w-16 items-center justify-center rounded-full bg-sky-600 text-white shadow-md"
                        >
                            <Camera class="h-8 w-8" />
                        </div>
                        <p class="text-sm font-bold text-slate-900">
                            {{ selectedFile ? selectedFile.name : 'Tap to Take Photo or Scan Document' }}
                        </p>
                        <p class="mt-1 text-xs text-slate-500">
                            Auto-deskew · OCR · AI Invoice Detection · Direct Accounting ERP Push
                        </p>
                    </label>

                    <div class="grid grid-cols-1 gap-3 text-xs sm:grid-cols-2">
                        <div class="sm:col-span-2">
                            <label class="mb-1 block font-semibold text-slate-700">Title (Optional)</label>
                            <input
                                v-model="captureTitle"
                                type="text"
                                placeholder="e.g. Fuel Receipt, Supplier Tax Invoice, Delivery Note"
                                class="w-full rounded-xl border border-slate-300 px-3 py-2"
                            />
                        </div>
                        <div>
                            <label class="mb-1 block font-semibold text-slate-700">Target Cabinet</label>
                            <select v-model="cabinetId" class="w-full rounded-xl border border-slate-300 px-3 py-2">
                                <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-semibold text-slate-700">Security Classification</label>
                            <select v-model="clearanceLevelId" class="w-full rounded-xl border border-slate-300 px-3 py-2">
                                <option v-for="l in allowedClearances" :key="l.id" :value="l.id">{{ l.name }}</option>
                            </select>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-4 text-xs font-medium text-slate-700">
                        <label class="inline-flex items-center gap-1.5">
                            <input v-model="autoDeskew" type="checkbox" class="rounded" />
                            Auto-Deskew &amp; Crop
                        </label>
                        <label class="inline-flex items-center gap-1.5">
                            <input v-model="forceInvoice" type="checkbox" class="rounded" />
                            Treat as Accounting Invoice
                        </label>
                    </div>

                    <p v-if="captureError" class="rounded-xl bg-rose-50 px-3 py-2 text-xs font-semibold text-rose-700">
                        {{ captureError }}
                    </p>

                    <button
                        type="button"
                        :disabled="!selectedFile || captureBusy"
                        @click="submitMobileCapture"
                        class="flex w-full items-center justify-center gap-2 rounded-xl px-4 py-3.5 text-sm font-bold text-white shadow-sm transition disabled:opacity-50"
                        :class="effectivelyOffline ? 'bg-amber-600 hover:bg-amber-700' : 'bg-sky-600 hover:bg-sky-700'"
                    >
                        <Loader2 v-if="captureBusy" class="h-4 w-4 animate-spin" />
                        <CloudUpload v-else class="h-4 w-4" />
                        <span>
                            {{
                                captureBusy
                                    ? 'Scanning, Running OCR & AI Detection...'
                                    : effectivelyOffline
                                      ? 'Queue Photo Offline (Sync Later)'
                                      : 'Upload, Scan & Detect with AI'
                            }}
                        </span>
                    </button>
                </div>
            </div>

            <!-- TAB 2: OFFLINE QUEUE -->
            <div v-if="activeTab === 'queue'" class="space-y-4">
                <div class="rounded-2xl border border-slate-200 bg-white p-4 text-xs shadow-xs">
                    <div class="flex items-center justify-between gap-2">
                        <div>
                            <h2 class="text-sm font-bold text-slate-900">Offline Device Queue ({{ offlineQueue.length }})</h2>
                            <p class="text-[11px] text-slate-500">Stored locally in IndexedDB until your phone reconnects to LAN/Cloud.</p>
                        </div>
                        <button
                            type="button"
                            :disabled="offlineQueue.length === 0 || queueSyncBusy || effectivelyOffline"
                            @click="syncOfflineQueueNow"
                            class="inline-flex items-center gap-1.5 rounded-xl bg-emerald-600 px-3.5 py-2 font-bold text-white hover:bg-emerald-700 disabled:opacity-50"
                        >
                            <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': queueSyncBusy }" />
                            Sync All
                        </button>
                    </div>

                    <p v-if="queueStatusMsg" class="mt-3 rounded-xl bg-sky-50 px-3 py-2 font-semibold text-sky-900">
                        {{ queueStatusMsg }}
                    </p>

                    <div v-if="offlineQueue.length === 0" class="mt-6 py-6 text-center text-slate-500">
                        No offline photos pending. Everything is synced!
                    </div>

                    <div v-else class="mt-4 space-y-2.5">
                        <div
                            v-for="item in offlineQueue"
                            :key="item.id"
                            class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 p-3"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-bold text-slate-900">{{ item.title }}</p>
                                <p class="truncate text-[11px] text-slate-500">
                                    {{ item.filename }} · Queued {{ new Date(item.queued_at).toLocaleString() }}
                                </p>
                            </div>
                            <button
                                type="button"
                                @click="deleteQueuedItem(item.id)"
                                class="rounded-lg p-2 text-rose-600 hover:bg-rose-50"
                                title="Remove queued photo"
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: RECENT MOBILE DOCUMENTS -->
            <div v-if="activeTab === 'docs'" class="space-y-3">
                <div class="relative">
                    <Search class="pointer-events-none absolute top-1/2 left-3.5 h-4 w-4 -translate-y-1/2 text-slate-400" />
                    <input
                        v-model="docsSearch"
                        type="text"
                        placeholder="Search your documents, invoices, cabinets..."
                        class="w-full rounded-2xl border border-slate-300 bg-white py-2.5 pr-4 pl-10 text-xs shadow-xs"
                    />
                </div>

                <div v-if="filteredDocs.length === 0" class="rounded-2xl border border-slate-200 bg-white p-8 text-center text-xs text-slate-500">
                    No matching documents found.
                </div>

                <div v-else class="space-y-2.5">
                    <div
                        v-for="doc in filteredDocs"
                        :key="doc.id"
                        class="rounded-2xl border border-slate-200 bg-white p-3.5 text-xs shadow-xs"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="truncate font-bold text-slate-900">{{ doc.title }}</p>
                                <p class="mt-0.5 text-[11px] text-slate-500">
                                    <span class="font-mono">{{ doc.doc_number }}</span> · {{ doc.cabinet_name }} · {{ doc.classification_name }}
                                </p>
                            </div>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-[10px] font-bold uppercase"
                                :class="
                                    doc.status === 'archived'
                                        ? 'bg-emerald-100 text-emerald-800'
                                        : 'bg-slate-100 text-slate-700'
                                "
                            >
                                {{ doc.status }}
                            </span>
                        </div>

                        <div
                            v-if="doc.mobile_erp_processing"
                            class="mt-2 flex items-center gap-2 rounded-lg bg-emerald-50 px-2.5 py-1.5 text-[11px] text-emerald-900"
                        >
                            <ShieldCheck class="h-3.5 w-3.5 shrink-0 text-emerald-700" />
                            <span class="truncate">
                                ERP: <strong>{{ doc.mobile_erp_processing.erp_target_name }}</strong>
                                ({{ doc.mobile_erp_processing.erp_sync_status }}) ·
                                {{ doc.mobile_erp_processing.invoice_number }}
                            </span>
                        </div>

                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <Link
                                :href="`/documents/${doc.id}`"
                                class="inline-flex items-center gap-1 rounded-lg border border-slate-300 px-2.5 py-1.5 font-semibold text-slate-700 hover:bg-slate-50"
                            >
                                <FolderOpen class="h-3.5 w-3.5" /> Open
                            </Link>
                            <button
                                v-if="!doc.mobile_erp_processing"
                                type="button"
                                @click="startInvoiceForExistingDoc(doc)"
                                class="inline-flex items-center gap-1 rounded-lg bg-emerald-50 px-2.5 py-1.5 font-semibold text-emerald-800 hover:bg-emerald-100"
                            >
                                <Receipt class="h-3.5 w-3.5" /> Send to ERP
                            </button>
                            <button
                                v-if="doc.status !== 'archived'"
                                type="button"
                                @click="quickManageDocument(doc.id, 'archived')"
                                class="inline-flex items-center gap-1 rounded-lg bg-amber-50 px-2.5 py-1.5 font-semibold text-amber-800 hover:bg-amber-100"
                            >
                                <Archive class="h-3.5 w-3.5" /> Archive
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mobile-First Fixed Bottom Navigation Bar -->
        <nav
            class="fixed right-0 bottom-0 left-0 z-40 border-t border-slate-200 bg-white/95 backdrop-blur md:left-64"
            aria-label="Mobile Capture Navigation"
        >
            <div class="mx-auto grid max-w-2xl grid-cols-3 text-xs font-semibold">
                <button
                    type="button"
                    @click="activeTab = 'snap'"
                    class="flex flex-col items-center justify-center gap-1 py-2.5 transition"
                    :class="activeTab === 'snap' ? 'text-sky-600' : 'text-slate-500 hover:text-slate-800'"
                >
                    <Camera class="h-5 w-5" />
                    <span>Snap &amp; ERP</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'queue'"
                    class="relative flex flex-col items-center justify-center gap-1 py-2.5 transition"
                    :class="activeTab === 'queue' ? 'text-sky-600' : 'text-slate-500 hover:text-slate-800'"
                >
                    <WifiOff class="h-5 w-5" />
                    <span>Offline ({{ offlineQueue.length }})</span>
                </button>
                <button
                    type="button"
                    @click="activeTab = 'docs'"
                    class="flex flex-col items-center justify-center gap-1 py-2.5 transition"
                    :class="activeTab === 'docs' ? 'text-sky-600' : 'text-slate-500 hover:text-slate-800'"
                >
                    <FileText class="h-5 w-5" />
                    <span>My Docs</span>
                </button>
            </div>
        </nav>
    </AppLayout>
</template>
