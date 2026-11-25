<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Building2,
    CheckCircle2,
    Download,
    FileSpreadsheet,
    Plus,
    Receipt,
    Send,
    ShieldCheck,
    Trash2,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface ComplianceChecklistItem {
    key: string;
    label: string;
    required_for: string;
    status: string;
    reference: string | null;
    expires_at: string | null;
    document_id: string | null;
}

interface VendorComplianceSummary {
    itf263_status: string;
    itf263_valid: boolean;
    wht_required: boolean;
    wht_rate_bps: number;
    wht_rate_percent: number;
    days_to_itf263_expiry: number | null;
    completeness_percent: number;
    checklist: ComplianceChecklistItem[];
}

interface VendorRow {
    id: string;
    code: string;
    name: string;
    tax_id_tin: string | null;
    vat_number: string | null;
    itf263_certificate_no?: string | null;
    itf263_expires_at?: string | null;
    itf263_document_id?: string | null;
    praz_registration_no?: string | null;
    praz_expires_at?: string | null;
    nssa_clearance_no?: string | null;
    nssa_expires_at?: string | null;
    currency: string;
    peppol_endpoint_id: string | null;
    email: string | null;
    is_active: boolean;
    compliance?: VendorComplianceSummary;
}

interface ExchangeRateRow {
    id: string;
    base_currency: string;
    quote_currency: string;
    rate: number;
    effective_date: string;
    source: string;
}

interface PoLineRow {
    id: string;
    line_number: number;
    sku: string;
    description: string;
    qty_ordered: number;
    qty_received: number;
    unit_price_cents: number;
}

interface PurchaseOrderRow {
    id: string;
    po_number: string;
    vendor_id: string;
    vendor_name: string;
    status: string;
    currency: string;
    total_cents: number;
    issued_at: string;
    grn_count: number;
    lines: PoLineRow[];
}

interface ApInvoiceRow {
    id: string;
    invoice_number: string;
    invoice_date: string;
    vendor_name: string;
    vendor_tin: string;
    po_number: string | null;
    document_id: string;
    doc_number: string;
    currency: string;
    subtotal_cents: number;
    vat_cents: number;
    total_cents: number;
    wht_applicable?: boolean;
    wht_rate_bps?: number;
    wht_cents?: number;
    net_payable_cents?: number;
    exchange_rate_to_usd?: number;
    usd_equivalent_cents?: number;
    status: string;
    match_mode: string;
    match_report: Record<string, unknown>;
    zimra_fiscal_status: string;
    zimra_receipt_no: string | null;
    zimra_qr_payload: string | null;
    fdms_qr_verified?: boolean;
    fdms_verification?: Record<string, unknown> | null;
    ubl_xml_hash: string | null;
    approver_name: string | null;
    lines: {
        id: string;
        sku: string;
        description: string;
        qty_invoiced: number;
        unit_price_cents: number;
        line_total_cents: number;
        variance_notes?: string | null;
    }[];
}

const props = defineProps<{
    vendors: VendorRow[];
    purchaseOrders: PurchaseOrderRow[];
    invoices: ApInvoiceRow[];
    exchangeRates?: ExchangeRateRow[];
    clearedDocuments: { id: string; doc_number: string; title: string; classification_rank: number }[];
    cabinets: { id: string; name: string }[];
    allowedClearances: { id: string; rank: number; name: string }[];
    canApproveInvoices: boolean;
}>();

const activeTab = ref<'invoices' | 'pos' | 'vendors' | 'compliance'>('invoices');
const overrideReasonMap = ref<Record<string, string>>({});
const fdmsQrInputs = ref<Record<string, string>>({});

const fxForm = useForm({
    quote_currency: 'ZWG',
    rate: 26.5,
    effective_date: new Date().toISOString().slice(0, 10),
    source: 'RBZ Interbank',
});

const complianceVendorId = ref<string>(props.vendors[0]?.id ?? '');
const complianceForm = useForm({
    itf263_certificate_no: props.vendors[0]?.itf263_certificate_no ?? '',
    itf263_expires_at: props.vendors[0]?.itf263_expires_at ?? '',
    itf263_document_id: props.vendors[0]?.itf263_document_id ?? '',
    praz_registration_no: props.vendors[0]?.praz_registration_no ?? '',
    praz_expires_at: props.vendors[0]?.praz_expires_at ?? '',
    nssa_clearance_no: props.vendors[0]?.nssa_clearance_no ?? '',
    nssa_expires_at: props.vendors[0]?.nssa_expires_at ?? '',
});

const vendorForm = useForm({
    code: '',
    name: '',
    tax_id_tin: '',
    vat_number: '',
    currency: 'USD',
    peppol_endpoint_id: '',
    email: '',
});

const poForm = useForm({
    vendor_id: props.vendors[0]?.id ?? '',
    po_number: `PO-${new Date().getFullYear()}-${Math.floor(100 + Math.random() * 900)}`,
    issued_at: new Date().toISOString().slice(0, 10),
    currency: 'USD',
    lines: [
        { sku: 'SVC-SUPPORT', description: 'Enterprise Retainer Service', qty_ordered: 1, unit_price_cents: 10000 },
    ],
});

const invoiceForm = useForm({
    vendor_id: props.vendors[0]?.id ?? '',
    purchase_order_id: '',
    document_id: '',
    cabinet_id: props.cabinets[0]?.id ?? '',
    classification_level_id: props.allowedClearances[0]?.id ?? '',
    invoice_number: '',
    invoice_date: new Date().toISOString().slice(0, 10),
    due_date: '',
    currency: 'USD',
    subtotal_cents: 0,
    vat_cents: 0,
    total_cents: 0,
    match_mode: '3_way' as '2_way' | '3_way',
    lines: [
        { sku: 'SVC-MAINT-Q4', description: 'Line Item', qty_invoiced: 1, unit_price_cents: 10000 },
    ],
});

const computedSubtotalCents = computed(() =>
    invoiceForm.lines.reduce((acc, l) => acc + Math.round(Number(l.qty_invoiced) * Number(l.unit_price_cents)), 0),
);
const computedVatCents = computed(() => Math.round(computedSubtotalCents.value * 0.15));
const computedTotalCents = computed(() => computedSubtotalCents.value + computedVatCents.value);

function syncFromPo(poId: string): void {
    const po = props.purchaseOrders.find((p) => p.id === poId);
    if (!po) {
        return;
    }
    invoiceForm.vendor_id = po.vendor_id;
    invoiceForm.currency = po.currency;
    if (po.lines.length > 0) {
        invoiceForm.lines = po.lines.map((l) => ({
            sku: l.sku,
            description: l.description,
            qty_invoiced: l.qty_ordered,
            unit_price_cents: l.unit_price_cents,
        }));
    }
}

function submitInvoice(): void {
    invoiceForm.subtotal_cents = computedSubtotalCents.value;
    invoiceForm.vat_cents = computedVatCents.value;
    invoiceForm.total_cents = computedTotalCents.value;
    invoiceForm.post('/finance/invoices', {
        preserveScroll: true,
        onSuccess: () => {
            invoiceForm.invoice_number = '';
        },
    });
}

function submitVendor(): void {
    vendorForm.post('/finance/vendors', {
        preserveScroll: true,
        onSuccess: () => vendorForm.reset(),
    });
}

function submitPo(): void {
    poForm.post('/finance/purchase-orders', {
        preserveScroll: true,
    });
}

function recordFullGrn(po: PurchaseOrderRow): void {
    router.post(
        `/finance/purchase-orders/${po.id}/receipts`,
        {
            grn_number: `GRN-${po.po_number}-${Date.now().toString().slice(-4)}`,
            notes: `Full receipt verified for ${po.po_number}`,
            lines: po.lines.map((l) => ({
                purchase_order_line_id: l.id,
                qty_received: Math.max(0.01, l.qty_ordered - l.qty_received),
            })),
        },
        { preserveScroll: true },
    );
}

function approveInvoice(inv: ApInvoiceRow): void {
    router.post(
        `/finance/invoices/${inv.id}/approve`,
        { override_reason: overrideReasonMap.value[inv.id] || null },
        { preserveScroll: true },
    );
}

function fiscalizeInvoice(inv: ApInvoiceRow): void {
    router.post(`/finance/invoices/${inv.id}/fiscalize`, {}, { preserveScroll: true });
}

function verifyFdmsQr(inv: ApInvoiceRow): void {
    const payload = (fdmsQrInputs.value[inv.id] ?? '').trim();
    if (!payload) {
        return;
    }
    router.post(`/finance/invoices/${inv.id}/verify-fdms-qr`, { qr_payload: payload }, { preserveScroll: true });
}

function submitExchangeRate(): void {
    fxForm.post('/finance/exchange-rates', { preserveScroll: true });
}

function selectComplianceVendor(vendorId: string): void {
    complianceVendorId.value = vendorId;
    const v = props.vendors.find((x) => x.id === vendorId);
    if (!v) {
        return;
    }
    complianceForm.itf263_certificate_no = v.itf263_certificate_no ?? '';
    complianceForm.itf263_expires_at = v.itf263_expires_at ?? '';
    complianceForm.itf263_document_id = v.itf263_document_id ?? '';
    complianceForm.praz_registration_no = v.praz_registration_no ?? '';
    complianceForm.praz_expires_at = v.praz_expires_at ?? '';
    complianceForm.nssa_clearance_no = v.nssa_clearance_no ?? '';
    complianceForm.nssa_expires_at = v.nssa_expires_at ?? '';
}

function submitVendorCompliance(): void {
    if (!complianceVendorId.value) {
        return;
    }
    complianceForm.patch(`/finance/vendors/${complianceVendorId.value}/compliance`, { preserveScroll: true });
}

function formatMoney(cents: number, currency: string): string {
    return `${currency} ${(cents / 100).toFixed(2)}`;
}

function statusPillClass(status: string): string {
    switch (status) {
        case 'matched':
        case 'approved':
        case 'fiscalized':
            return 'bg-emerald-100 text-emerald-800';
        case 'variance_flagged':
        case 'queued_offline':
            return 'bg-amber-100 text-amber-800';
        case 'duplicate_blocked':
        case 'rejected':
        case 'failed':
            return 'bg-red-100 text-red-800';
        default:
            return 'bg-slate-100 text-slate-700';
    }
}
</script>

<template>
    <Head title="AP Automation & ZIMRA E-Invoicing" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Accounts Payable & ZIMRA FDMS E-Invoicing</h1>
                    <p class="text-xs text-slate-600">
                        Automated 2/3-way PO + GRN + Invoice matching, duplicate prevention, UBL 2.1 XML & ZIMRA fiscalization.
                    </p>
                </div>
                <div class="flex gap-2 rounded-lg border border-slate-200 bg-white p-1 text-xs font-semibold">
                    <button
                        type="button"
                        @click="activeTab = 'invoices'"
                        class="rounded-md px-3 py-1.5"
                        :class="activeTab === 'invoices' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                    >
                        Invoices ({{ invoices.length }})
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'pos'"
                        class="rounded-md px-3 py-1.5"
                        :class="activeTab === 'pos' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                    >
                        POs & GRNs ({{ purchaseOrders.length }})
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'vendors'"
                        class="rounded-md px-3 py-1.5"
                        :class="activeTab === 'vendors' ? 'bg-slate-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
                    >
                        Vendors ({{ vendors.length }})
                    </button>
                    <button
                        type="button"
                        @click="activeTab = 'compliance'"
                        class="rounded-md px-3 py-1.5"
                        :class="activeTab === 'compliance' ? 'bg-emerald-700 text-white' : 'text-emerald-700 hover:bg-emerald-50'"
                    >
                        ZIMRA ITF263, PRAZ &amp; RBZ FX
                    </button>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Registered Vendors</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ vendors.length }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Purchase Orders</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ purchaseOrders.length }}</p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Matched / Approved</p>
                    <p class="mt-1 text-2xl font-bold text-emerald-700">
                        {{ invoices.filter((i) => i.status === 'matched' || i.status === 'approved').length }}
                    </p>
                </div>
                <div class="rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Exceptions / Blocked</p>
                    <p class="mt-1 text-2xl font-bold text-amber-700">
                        {{ invoices.filter((i) => i.status === 'variance_flagged' || i.status === 'duplicate_blocked').length }}
                    </p>
                </div>
            </div>

            <!-- TAB: INVOICES & 3-WAY MATCH -->
            <div v-if="activeTab === 'invoices'" class="space-y-6">
                <form @submit.prevent="submitInvoice" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <Receipt class="h-4 w-4 text-sky-600" />
                        Capture Vendor Invoice & Execute Match
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600">Link Purchase Order (optional)</label>
                            <select
                                v-model="invoiceForm.purchase_order_id"
                                @change="syncFromPo(invoiceForm.purchase_order_id)"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs"
                            >
                                <option value="">-- No PO / 2-Way Manual --</option>
                                <option v-for="po in purchaseOrders" :key="po.id" :value="po.id">
                                    {{ po.po_number }} — {{ po.vendor_name }} ({{ formatMoney(po.total_cents, po.currency) }})
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600">Vendor</label>
                            <select v-model="invoiceForm.vendor_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                                <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }} ({{ v.code }})</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600">Invoice Number</label>
                            <input
                                v-model="invoiceForm.invoice_number"
                                type="text"
                                required
                                placeholder="INV-2026-0881"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs"
                            />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600">Match Mode</label>
                            <select v-model="invoiceForm.match_mode" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                                <option value="3_way">3-Way (PO + GRN + Invoice)</option>
                                <option value="2_way">2-Way (PO + Invoice)</option>
                            </select>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600">Invoice Date</label>
                            <input v-model="invoiceForm.invoice_date" type="date" required class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600">Existing Vault Doc (optional)</label>
                            <select v-model="invoiceForm.document_id" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                                <option value="">-- Auto-file AP Document --</option>
                                <option v-for="doc in clearedDocuments" :key="doc.id" :value="doc.id">{{ doc.doc_number }} — {{ doc.title }}</option>
                            </select>
                        </div>
                        <div v-if="!invoiceForm.document_id">
                            <label class="block text-[11px] font-semibold text-slate-600">Cabinet</label>
                            <select v-model="invoiceForm.cabinet_id" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                                <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                        </div>
                        <div v-if="!invoiceForm.document_id">
                            <label class="block text-[11px] font-semibold text-slate-600">Clearance</label>
                            <select v-model="invoiceForm.classification_level_id" class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                                <option v-for="lvl in allowedClearances" :key="lvl.id" :value="lvl.id">L{{ lvl.rank }} — {{ lvl.name }}</option>
                            </select>
                        </div>
                    </div>

                    <!-- Invoice Lines -->
                    <div class="space-y-2 rounded-lg border border-slate-200 bg-slate-50 p-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-semibold text-slate-700">Invoice Line Items</span>
                            <button
                                type="button"
                                @click="invoiceForm.lines.push({ sku: '', description: '', qty_invoiced: 1, unit_price_cents: 0 })"
                                class="inline-flex items-center gap-1 text-xs font-semibold text-sky-700 hover:underline"
                            >
                                <Plus class="h-3.5 w-3.5" /> Add Line
                            </button>
                        </div>
                        <div v-for="(line, idx) in invoiceForm.lines" :key="idx" class="grid grid-cols-12 gap-2 items-center">
                            <input v-model="line.sku" placeholder="SKU" required class="col-span-2 rounded border border-slate-300 bg-white px-2 py-1 text-xs" />
                            <input v-model="line.description" placeholder="Description" required class="col-span-5 rounded border border-slate-300 bg-white px-2 py-1 text-xs" />
                            <input v-model.number="line.qty_invoiced" type="number" step="0.01" min="0.01" required class="col-span-2 rounded border border-slate-300 bg-white px-2 py-1 text-xs" />
                            <input v-model.number="line.unit_price_cents" type="number" min="0" required placeholder="Cents" class="col-span-2 rounded border border-slate-300 bg-white px-2 py-1 text-xs" />
                            <button
                                v-if="invoiceForm.lines.length > 1"
                                type="button"
                                @click="invoiceForm.lines.splice(idx, 1)"
                                class="col-span-1 flex justify-center text-red-600 hover:text-red-800"
                            >
                                <Trash2 class="h-4 w-4" />
                            </button>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="font-mono text-xs text-slate-700">
                            Subtotal: <strong>{{ formatMoney(computedSubtotalCents, invoiceForm.currency) }}</strong> ·
                            VAT (15%): <strong>{{ formatMoney(computedVatCents, invoiceForm.currency) }}</strong> ·
                            Total: <strong>{{ formatMoney(computedTotalCents, invoiceForm.currency) }}</strong>
                        </div>
                        <button
                            type="submit"
                            :disabled="invoiceForm.processing"
                            class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            Capture & Run Match
                        </button>
                    </div>
                </form>

                <!-- AP Invoices Table -->
                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50 text-left font-semibold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Invoice / Document</th>
                                <th class="px-4 py-3">Vendor / PO</th>
                                <th class="px-4 py-3">Total</th>
                                <th class="px-4 py-3">Match Result</th>
                                <th class="px-4 py-3">ZIMRA FDMS</th>
                                <th class="px-4 py-3">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="inv in invoices" :key="inv.id" class="hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="font-mono font-bold text-slate-900">{{ inv.invoice_number }}</div>
                                    <Link :href="`/documents/${inv.document_id}`" class="text-[11px] text-sky-700 hover:underline">
                                        {{ inv.doc_number }}
                                    </Link>
                                </td>
                                <td class="px-4 py-3">
                                    <div class="font-semibold text-slate-800">{{ inv.vendor_name }}</div>
                                    <div class="font-mono text-[11px] text-slate-500">{{ inv.po_number ?? 'No PO' }} · {{ inv.match_mode }}</div>
                                </td>
                                <td class="px-4 py-3 font-mono font-semibold">{{ formatMoney(inv.total_cents, inv.currency) }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="statusPillClass(inv.status)">
                                        <CheckCircle2 v-if="inv.status === 'matched' || inv.status === 'approved'" class="h-3 w-3" />
                                        <AlertTriangle v-else class="h-3 w-3" />
                                        {{ inv.status }}
                                    </span>
                                    <div v-if="Array.isArray(inv.match_report.reasons) && inv.match_report.reasons.length" class="mt-1 text-[11px] text-amber-800">
                                        {{ (inv.match_report.reasons as string[]).join(' | ') }}
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="statusPillClass(inv.zimra_fiscal_status)">
                                        {{ inv.zimra_fiscal_status }}
                                    </span>
                                    <div v-if="inv.zimra_receipt_no" class="mt-0.5 font-mono text-[10px] text-slate-500">
                                        {{ inv.zimra_receipt_no }}
                                    </div>
                                </td>
                                <td class="px-4 py-3 space-y-1.5">
                                    <div class="flex flex-wrap gap-1.5">
                                        <a
                                            :href="`/finance/invoices/${inv.id}/ubl`"
                                            class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2 py-1 text-[11px] font-semibold text-slate-700 hover:bg-slate-50"
                                        >
                                            <Download class="h-3 w-3" /> UBL XML
                                        </a>
                                        <button
                                            v-if="inv.zimra_fiscal_status !== 'fiscalized'"
                                            type="button"
                                            @click="fiscalizeInvoice(inv)"
                                            class="inline-flex items-center gap-1 rounded bg-sky-700 px-2 py-1 text-[11px] font-semibold text-white hover:bg-sky-800"
                                        >
                                            <Send class="h-3 w-3" /> Fiscalize ZIMRA
                                        </button>
                                    </div>
                                    <div v-if="inv.wht_applicable" class="rounded bg-amber-50 px-2 py-1 text-[10px] font-semibold text-amber-900">
                                        30% ZIMRA WHT: {{ formatMoney(inv.wht_cents ?? 0, inv.currency) }} · Net: {{ formatMoney(inv.net_payable_cents ?? inv.total_cents, inv.currency) }}
                                    </div>
                                    <div v-if="inv.currency !== 'USD' && inv.usd_equivalent_cents" class="text-[10px] font-mono text-slate-500">
                                        USD Eq: {{ formatMoney(inv.usd_equivalent_cents, 'USD') }} (@ {{ inv.exchange_rate_to_usd }})
                                    </div>
                                    <div class="flex items-center gap-1">
                                        <input
                                            v-model="fdmsQrInputs[inv.id]"
                                            type="text"
                                            placeholder="Paste ZIMRA FDMS QR URL..."
                                            class="w-36 rounded border border-slate-300 px-1.5 py-0.5 text-[10px] font-mono"
                                        />
                                        <button
                                            type="button"
                                            @click="verifyFdmsQr(inv)"
                                            class="rounded bg-slate-800 px-2 py-0.5 text-[10px] font-semibold text-white hover:bg-slate-700"
                                        >
                                            {{ inv.fdms_qr_verified ? 'QR Verified ✓' : 'Verify QR' }}
                                        </button>
                                    </div>
                                    <div v-if="canApproveInvoices && inv.status !== 'approved' && inv.status !== 'duplicate_blocked'" class="flex items-center gap-1">
                                        <input
                                            v-if="inv.status === 'variance_flagged'"
                                            v-model="overrideReasonMap[inv.id]"
                                            type="text"
                                            placeholder="Variance override justification..."
                                            class="w-40 rounded border border-amber-300 px-1.5 py-0.5 text-[11px]"
                                        />
                                        <button
                                            type="button"
                                            @click="approveInvoice(inv)"
                                            class="inline-flex items-center gap-1 rounded bg-emerald-700 px-2 py-1 text-[11px] font-semibold text-white hover:bg-emerald-800"
                                        >
                                            <ShieldCheck class="h-3 w-3" /> Approve
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB: PURCHASE ORDERS & GOODS RECEIPTS -->
            <div v-if="activeTab === 'pos'" class="space-y-6">
                <form @submit.prevent="submitPo" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <FileSpreadsheet class="h-4 w-4 text-sky-600" />
                        Issue Purchase Order
                    </div>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600">Vendor</label>
                            <select v-model="poForm.vendor_id" required class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                                <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600">PO Number</label>
                            <input v-model="poForm.po_number" required class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600">Issued Date</label>
                            <input v-model="poForm.issued_at" type="date" required class="mt-1 w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                        </div>
                    </div>
                    <div v-for="(line, idx) in poForm.lines" :key="idx" class="grid grid-cols-12 gap-2">
                        <input v-model="line.sku" placeholder="SKU" required class="col-span-2 rounded border border-slate-300 px-2 py-1 text-xs" />
                        <input v-model="line.description" placeholder="Item Description" required class="col-span-5 rounded border border-slate-300 px-2 py-1 text-xs" />
                        <input v-model.number="line.qty_ordered" type="number" min="1" required class="col-span-2 rounded border border-slate-300 px-2 py-1 text-xs" />
                        <input v-model.number="line.unit_price_cents" type="number" min="0" required class="col-span-3 rounded border border-slate-300 px-2 py-1 text-xs" />
                    </div>
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                        Create Purchase Order
                    </button>
                </form>

                <div class="space-y-3">
                    <div v-for="po in purchaseOrders" :key="po.id" class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <span class="font-mono text-sm font-bold text-slate-900">{{ po.po_number }}</span>
                                <span class="ml-2 text-xs text-slate-600">{{ po.vendor_name }} · Issued {{ po.issued_at }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-[11px] font-semibold text-slate-700">
                                    {{ po.status }} ({{ po.grn_count }} GRNs)
                                </span>
                                <button
                                    type="button"
                                    @click="recordFullGrn(po)"
                                    class="rounded-lg bg-emerald-700 px-3 py-1 text-xs font-semibold text-white hover:bg-emerald-800"
                                >
                                    Record Remaining GRN
                                </button>
                            </div>
                        </div>
                        <div class="mt-3 grid gap-1 text-xs text-slate-700">
                            <div v-for="l in po.lines" :key="l.id" class="flex justify-between rounded bg-slate-50 px-3 py-1.5 font-mono">
                                <span>{{ l.sku }} — {{ l.description }}</span>
                                <span>Ordered: {{ l.qty_ordered }} | Received: {{ l.qty_received }} @ {{ formatMoney(l.unit_price_cents, po.currency) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB: VENDORS -->
            <div v-if="activeTab === 'vendors'" class="space-y-6">
                <form @submit.prevent="submitVendor" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <Building2 class="h-4 w-4 text-sky-600" />
                        Register Approved Supplier / Vendor
                    </div>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <input v-model="vendorForm.code" placeholder="Vendor Code (e.g. VND-099)" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                        <input v-model="vendorForm.name" placeholder="Legal Entity Name" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                        <input v-model="vendorForm.tax_id_tin" placeholder="ZIMRA TIN (10 digits)" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                        <input v-model="vendorForm.vat_number" placeholder="VAT Registration No" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                        <input v-model="vendorForm.peppol_endpoint_id" placeholder="PEPPOL Endpoint ID" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                        <input v-model="vendorForm.email" type="email" placeholder="Accounts Email" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    </div>
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                        Save Vendor
                    </button>
                </form>

                <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                    <table class="min-w-full divide-y divide-slate-200 text-xs">
                        <thead class="bg-slate-50 text-left font-semibold uppercase tracking-wider text-slate-500">
                            <tr>
                                <th class="px-4 py-3">Code</th>
                                <th class="px-4 py-3">Name</th>
                                <th class="px-4 py-3">TIN / VAT</th>
                                <th class="px-4 py-3">PEPPOL ID</th>
                                <th class="px-4 py-3">Currency</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="v in vendors" :key="v.id" class="hover:bg-slate-50">
                                <td class="px-4 py-2.5 font-mono font-semibold">{{ v.code }}</td>
                                <td class="px-4 py-2.5 font-semibold text-slate-900">{{ v.name }}</td>
                                <td class="px-4 py-2.5 font-mono">{{ v.tax_id_tin ?? '—' }} / {{ v.vat_number ?? '—' }}</td>
                                <td class="px-4 py-2.5 font-mono text-slate-600">{{ v.peppol_endpoint_id ?? '—' }}</td>
                                <td class="px-4 py-2.5">{{ v.currency }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- TAB: ZIMBABWE COMPLIANCE (ITF263 / 30% WHT / PRAZ / NSSA) & RBZ ZWG/USD FX -->
            <div v-if="activeTab === 'compliance'" class="space-y-6">
                <!-- RBZ Official Exchange Rate Recorder -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="text-sm font-bold text-slate-900">RBZ Multi-Currency Exchange Rates (ZWG / ZAR / USD)</h2>
                            <p class="text-[11px] text-slate-500">
                                Automatically locked onto ZWG &amp; ZAR invoices on capture for ZIMRA audit trails and USD reporting.
                            </p>
                        </div>
                    </div>
                    <form @submit.prevent="submitExchangeRate" class="grid grid-cols-1 gap-3 md:grid-cols-5 text-xs">
                        <select v-model="fxForm.quote_currency" class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                            <option value="ZWG">USD / ZWG</option>
                            <option value="ZAR">USD / ZAR</option>
                            <option value="EUR">USD / EUR</option>
                            <option value="GBP">USD / GBP</option>
                        </select>
                        <input v-model.number="fxForm.rate" type="number" step="0.0001" min="0.0001" required placeholder="Rate per 1 USD" class="rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono" />
                        <input v-model="fxForm.effective_date" type="date" required class="rounded-lg border border-slate-300 px-2.5 py-1.5" />
                        <input v-model="fxForm.source" type="text" placeholder="RBZ Interbank" class="rounded-lg border border-slate-300 px-2.5 py-1.5" />
                        <button type="submit" class="rounded-lg bg-slate-900 px-3 py-1.5 font-semibold text-white hover:bg-slate-800">
                            Save Rate
                        </button>
                    </form>

                    <div v-if="exchangeRates && exchangeRates.length > 0" class="flex flex-wrap gap-2 pt-1">
                        <span
                            v-for="r in exchangeRates.slice(0, 8)"
                            :key="r.id"
                            class="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 font-mono text-[11px] text-slate-700"
                        >
                            1 {{ r.base_currency }} = <strong>{{ r.rate }} {{ r.quote_currency }}</strong> ({{ r.effective_date }} · {{ r.source }})
                        </span>
                    </div>
                </div>

                <!-- Vendor ITF263 / PRAZ / NSSA Compliance Updater -->
                <form @submit.prevent="submitVendorCompliance" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-4 text-xs">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="text-sm font-bold text-slate-900">Vendor ZIMRA ITF263 Tax Clearance, PRAZ &amp; NSSA Compliance</h2>
                            <p class="text-[11px] text-slate-500">
                                Missing or expired ZIMRA ITF263 automatically triggers 30% Withholding Tax (WHT) on AP invoices.
                            </p>
                        </div>
                        <select
                            :value="complianceVendorId"
                            @change="selectComplianceVendor(($event.target as HTMLSelectElement).value)"
                            class="rounded-lg border border-slate-300 px-3 py-1.5 font-semibold"
                        >
                            <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.name }} ({{ v.code }})</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                        <input v-model="complianceForm.itf263_certificate_no" placeholder="ZIMRA ITF263 Cert No" class="rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono" />
                        <input v-model="complianceForm.itf263_expires_at" type="date" placeholder="ITF263 Expiry" class="rounded-lg border border-slate-300 px-2.5 py-1.5" />
                        <select v-model="complianceForm.itf263_document_id" class="rounded-lg border border-slate-300 px-2.5 py-1.5">
                            <option value="">Link ITF263 Vault PDF (Optional)</option>
                            <option v-for="d in clearedDocuments" :key="d.id" :value="d.id">{{ d.doc_number }} — {{ d.title }}</option>
                        </select>
                        <input v-model="complianceForm.praz_registration_no" placeholder="PRAZ Registration No" class="rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono" />
                        <input v-model="complianceForm.praz_expires_at" type="date" placeholder="PRAZ Expiry" class="rounded-lg border border-slate-300 px-2.5 py-1.5" />
                        <div class="grid grid-cols-2 gap-2">
                            <input v-model="complianceForm.nssa_clearance_no" placeholder="NSSA Cert No" class="rounded-lg border border-slate-300 px-2 py-1.5 font-mono" />
                            <input v-model="complianceForm.nssa_expires_at" type="date" class="rounded-lg border border-slate-300 px-2 py-1.5" />
                        </div>
                    </div>
                    <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 font-semibold text-white hover:bg-emerald-800">
                        Update Statutory Compliance Pack
                    </button>
                </form>

                <!-- Vendor Compliance Scorecard -->
                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <div v-for="v in vendors" :key="v.id" class="rounded-xl border border-slate-200 bg-white p-4 text-xs shadow-xs">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="font-bold text-slate-900">{{ v.name }}</p>
                                <p class="font-mono text-[11px] text-slate-500">{{ v.code }} · TIN: {{ v.tax_id_tin ?? 'Missing' }}</p>
                            </div>
                            <span
                                class="rounded-full px-2.5 py-0.5 text-[10px] font-bold uppercase"
                                :class="v.compliance?.wht_required ? 'bg-rose-100 text-rose-800' : 'bg-emerald-100 text-emerald-800'"
                            >
                                {{ v.compliance?.wht_required ? '30% WHT Required' : 'ITF263 Valid' }}
                            </span>
                        </div>
                        <div class="mt-2.5 h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
                            <div
                                class="h-full rounded-full bg-emerald-600"
                                :style="{ width: `${v.compliance?.completeness_percent ?? 0}%` }"
                            />
                        </div>
                        <p class="mt-1 text-[10px] font-semibold text-slate-600">
                            Statutory Pack Completeness: {{ v.compliance?.completeness_percent ?? 0 }}%
                        </p>
                        <div v-if="v.compliance" class="mt-2 grid grid-cols-2 gap-1.5 text-[10px]">
                            <div
                                v-for="item in v.compliance.checklist"
                                :key="item.key"
                                class="flex items-center justify-between rounded bg-slate-50 px-2 py-1"
                            >
                                <span class="truncate font-medium text-slate-700">{{ item.label }}</span>
                                <span
                                    class="ml-1 font-bold uppercase"
                                    :class="
                                        item.status === 'valid'
                                            ? 'text-emerald-700'
                                            : item.status === 'expiring_soon'
                                              ? 'text-amber-700'
                                              : 'text-rose-700'
                                    "
                                >
                                    {{ item.status }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
