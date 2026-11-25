<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Download, KeyRound, Link2, PlugZap, Send, ShieldCheck, Trash2, Upload, Webhook } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

interface ApiTokenItem {
    id: string | number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    expires_at: string | null;
    created_at: string | null;
}

interface ConnectionItem {
    id: string;
    name: string;
    system_type: string;
    system_label: string;
    transport: string;
    base_url: string | null;
    is_active: boolean;
    last_sync_at: string | null;
}

interface WebhookItem {
    id: string;
    name: string;
    target_url: string;
    events: string[];
    is_active: boolean;
    last_dispatched_at: string | null;
    last_status_code: number | null;
    failure_count: number;
}

interface DeliveryItem {
    id: string;
    webhook_name: string;
    event_type: string;
    status: string;
    attempts: number;
    response_code: number | null;
    response_excerpt: string | null;
    dispatched_at: string | null;
}

interface MappingItem {
    id: string;
    external_system: string;
    entity_type: string;
    external_id: string;
    internal_id: string;
}

interface SystemOption {
    value: string;
    label: string;
}

const props = defineProps<{
    tokens: ApiTokenItem[];
    availableAbilities: string[];
    connections: ConnectionItem[];
    webhooks: WebhookItem[];
    deliveries: DeliveryItem[];
    mappings: MappingItem[];
    supportedEvents: string[];
    systemOptions: SystemOption[];
    issuedPlaintextToken: string | null;
    externalAllowlist: string[];
}>();

const tokenForm = useForm<{
    name: string;
    abilities: string[];
    expires_days: number;
}>({
    name: '',
    abilities: ['documents:read', 'documents:write', 'finance:sync'],
    expires_days: 90,
});

const connectionForm = useForm({
    name: '',
    system_type: props.systemOptions[0]?.value ?? 'insurance_core',
    transport: 'rest_api',
    base_url: '',
    api_key: '',
});

const webhookForm = useForm<{
    name: string;
    target_url: string;
    secret: string;
    events: string[];
}>({
    name: '',
    target_url: '',
    secret: '',
    events: ['document.ingested', 'ap.invoice_approved', 'webhook.test'],
});

const belinaCsvForm = useForm<{ csv_file: File | null }>({
    csv_file: null,
});

const sageCsvForm = useForm<{ csv_file: File | null }>({
    csv_file: null,
});

function toggleItem(list: string[], value: string): void {
    const idx = list.indexOf(value);
    if (idx === -1) {
        list.push(value);
    } else {
        list.splice(idx, 1);
    }
}

function createToken(): void {
    tokenForm.post('/admin/integrations/tokens', {
        preserveScroll: true,
        onSuccess: () => tokenForm.reset('name'),
    });
}

function revokeToken(id: string | number): void {
    router.delete(`/admin/integrations/tokens/${id}`, { preserveScroll: true });
}

function createConnection(): void {
    connectionForm.post('/admin/integrations/connections', {
        preserveScroll: true,
        onSuccess: () => connectionForm.reset('name', 'base_url', 'api_key'),
    });
}

function createWebhook(): void {
    webhookForm.post('/admin/integrations/webhooks', {
        preserveScroll: true,
        onSuccess: () => webhookForm.reset('name', 'target_url', 'secret'),
    });
}

function dispatchTestWebhooks(): void {
    router.post('/admin/integrations/webhooks/test', {}, { preserveScroll: true });
}

function submitBelinaCsv(): void {
    belinaCsvForm.post('/admin/integrations/import/belina-employees', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => belinaCsvForm.reset(),
    });
}

function submitSageCsv(): void {
    sageCsvForm.post('/admin/integrations/import/sage-vendors', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => sageCsvForm.reset(),
    });
}

function handleFileSelect(target: 'belina' | 'sage', event: Event): void {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;
    if (target === 'belina') {
        belinaCsvForm.csv_file = file;
    } else {
        sageCsvForm.csv_file = file;
    }
}
</script>

<template>
    <Head title="Integrations & ERP Hub" />

    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">ERP, Insurance &amp; Payroll Integration Hub (Stage 1)</h1>
                    <p class="text-xs text-slate-600">
                        Manage scoped Sanctum REST API v1 tokens, signed HMAC webhooks, and batch CSV bridges for Sage Pastel, Belina Payroll, Xero, NetSuite &amp; Insurance cores.
                    </p>
                </div>
                <button
                    type="button"
                    @click="dispatchTestWebhooks"
                    class="inline-flex items-center gap-2 rounded-lg bg-[#0E7C66] px-4 py-2 text-xs font-semibold text-white hover:opacity-95"
                >
                    <Send class="h-3.5 w-3.5" />
                    Send Test Webhook Ping
                </button>
            </div>

            <!-- One-Time Token Reveal Banner -->
            <div
                v-if="issuedPlaintextToken"
                role="status"
                class="rounded-[14px] border-2 border-emerald-500 bg-[#DDF3EC] p-4 text-xs text-[#0F172A] space-y-2"
            >
                <p class="font-bold flex items-center gap-1.5 text-[#0E7C66]">
                    <ShieldCheck class="h-4 w-4" />
                    Bearer Token Generated — Copy Immediately (shown once only):
                </p>
                <pre class="overflow-x-auto rounded-lg bg-[#0F172A] p-3 font-mono text-xs text-emerald-300 select-all">{{ issuedPlaintextToken }}</pre>
            </div>

            <!-- Scoped API Tokens & Active Connections -->
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
                <!-- Scoped Sanctum API Tokens -->
                <section class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-4 xl:col-span-6">
                    <div class="flex items-center gap-2 border-b border-slate-100 pb-3">
                        <KeyRound class="h-4 w-4 text-[#0E7C66]" />
                        <h2 class="text-sm font-bold text-slate-900">Scoped REST API v1 Tokens (/api/v1/*)</h2>
                    </div>

                    <form @submit.prevent="createToken" class="space-y-3">
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                            <div class="sm:col-span-2">
                                <label for="tok-name" class="block text-xs font-semibold text-slate-700">Integration Name *</label>
                                <input
                                    id="tok-name"
                                    v-model="tokenForm.name"
                                    type="text"
                                    required
                                    placeholder="e.g. Insurance Claims Bridge"
                                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                />
                            </div>
                            <div>
                                <label for="tok-exp" class="block text-xs font-semibold text-slate-700">Expiry (Days)</label>
                                <input
                                    id="tok-exp"
                                    v-model.number="tokenForm.expires_days"
                                    type="number"
                                    min="1"
                                    max="730"
                                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                />
                            </div>
                        </div>

                        <div>
                            <p class="text-xs font-semibold text-slate-700">Token Scopes *</p>
                            <div class="mt-1.5 flex flex-wrap gap-2">
                                <label
                                    v-for="ability in availableAbilities"
                                    :key="ability"
                                    class="inline-flex items-center gap-1.5 rounded border border-slate-200 px-2.5 py-1 text-xs font-mono text-slate-700 cursor-pointer"
                                >
                                    <input
                                        type="checkbox"
                                        :checked="tokenForm.abilities.includes(ability)"
                                        @change="toggleItem(tokenForm.abilities, ability)"
                                        class="rounded border-slate-300"
                                    />
                                    {{ ability }}
                                </label>
                            </div>
                        </div>

                        <button
                            type="submit"
                            :disabled="tokenForm.processing"
                            class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            Issue Scoped API Token
                        </button>
                    </form>

                    <div class="space-y-2 pt-2 border-t border-slate-100">
                        <p class="text-xs font-bold uppercase tracking-wider text-slate-500">Active Tokens ({{ tokens.length }})</p>
                        <div
                            v-for="tok in tokens"
                            :key="tok.id"
                            class="flex items-center justify-between gap-2 rounded-lg border border-slate-200 p-3 text-xs"
                        >
                            <div>
                                <p class="font-bold text-slate-900">{{ tok.name }}</p>
                                <p class="mt-0.5 font-mono text-[11px] text-slate-600">{{ tok.abilities.join(', ') }}</p>
                            </div>
                            <button
                                type="button"
                                @click="revokeToken(tok.id)"
                                class="inline-flex items-center gap-1 rounded border border-red-200 px-2.5 py-1 font-semibold text-red-700 hover:bg-red-50"
                            >
                                <Trash2 class="h-3.5 w-3.5" /> Revoke
                            </button>
                        </div>
                        <p v-if="tokens.length === 0" class="text-xs text-slate-500">No API tokens issued yet.</p>
                    </div>
                </section>

                <!-- ERP Connector Registry & Webhooks -->
                <section class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-5 xl:col-span-6">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <div class="flex items-center gap-2">
                            <PlugZap class="h-4 w-4 text-[#0E7C66]" />
                            <h2 class="text-sm font-bold text-slate-900">ERP Connectors &amp; Outbound HMAC Webhooks</h2>
                        </div>
                        <span class="text-[11px] font-mono text-slate-500">
                            Allowlist: {{ externalAllowlist.length > 0 ? externalAllowlist.join(', ') : 'LAN Only' }}
                        </span>
                    </div>

                    <form @submit.prevent="createConnection" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Connector Name *</label>
                            <input
                                v-model="connectionForm.name"
                                type="text"
                                required
                                placeholder="e.g. Sage Pastel Evolution HQ"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                            />
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">ERP / System *</label>
                            <select v-model="connectionForm.system_type" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                                <option v-for="opt in systemOptions" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700">Transport Mode</label>
                            <select v-model="connectionForm.transport" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm">
                                <option value="rest_api">REST API (Bearer / OAuth2)</option>
                                <option value="webhook">HMAC Outbound Webhook</option>
                                <option value="csv_batch">Batch CSV (Belina / Sage Pastel)</option>
                                <option value="local_bridge">LAN Agent Bridge</option>
                            </select>
                        </div>
                        <div class="flex items-end">
                            <button
                                type="submit"
                                :disabled="connectionForm.processing"
                                class="w-full rounded-lg border border-slate-300 bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                            >
                                Register Connector
                            </button>
                        </div>
                    </form>

                    <!-- Register Outbound Webhook -->
                    <form @submit.prevent="createWebhook" class="space-y-3 border-t border-slate-100 pt-4">
                        <p class="text-xs font-bold text-slate-800 flex items-center gap-1.5">
                            <Webhook class="h-3.5 w-3.5 text-[#0E7C66]" /> Subscribe Outbound HMAC-SHA256 Webhook
                        </p>
                        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <input
                                v-model="webhookForm.name"
                                type="text"
                                required
                                placeholder="Webhook Label (e.g. Core Claims Callback)"
                                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                            />
                            <input
                                v-model="webhookForm.target_url"
                                type="url"
                                required
                                placeholder="Target Endpoint URL"
                                class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                            />
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            <label
                                v-for="ev in supportedEvents"
                                :key="ev"
                                class="inline-flex items-center gap-1 rounded border border-slate-200 px-2 py-0.5 text-[11px] font-mono text-slate-700 cursor-pointer"
                            >
                                <input
                                    type="checkbox"
                                    :checked="webhookForm.events.includes(ev)"
                                    @change="toggleItem(webhookForm.events, ev)"
                                    class="rounded border-slate-300"
                                />
                                {{ ev }}
                            </label>
                        </div>
                        <button
                            type="submit"
                            :disabled="webhookForm.processing"
                            class="rounded-lg bg-[#0E7C66] px-4 py-1.5 text-xs font-semibold text-white hover:opacity-95"
                        >
                            Save Webhook Subscription
                        </button>
                    </form>

                    <div v-if="connections.length > 0 || webhooks.length > 0" class="space-y-2 border-t border-slate-100 pt-3 text-xs">
                        <div v-for="conn in connections" :key="conn.id" class="flex items-center justify-between rounded bg-slate-50 px-3 py-1.5">
                            <span><strong>{{ conn.name }}</strong> · {{ conn.system_label }}</span>
                            <span class="font-mono text-[11px] uppercase text-slate-500">{{ conn.transport }}</span>
                        </div>
                        <div v-for="wh in webhooks" :key="wh.id" class="flex items-center justify-between rounded bg-emerald-50/70 px-3 py-1.5">
                            <span><strong>{{ wh.name }}</strong> · <span class="font-mono">{{ wh.target_url }}</span></span>
                            <span class="font-mono text-[11px] text-[#0E7C66]">{{ wh.events.length }} events</span>
                        </div>
                    </div>
                </section>
            </div>

            <!-- Sage Pastel & Belina CSV Batch Sync -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Belina Payroll / HR CSV Bridge -->
                <section class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <h2 class="text-sm font-bold text-slate-900">Belina Payroll &amp; HR Batch Sync (CSV)</h2>
                        <a
                            href="/admin/integrations/templates/belina-employees"
                            class="inline-flex items-center gap-1 text-xs font-semibold text-[#0E7C66] hover:underline"
                        >
                            <Download class="h-3.5 w-3.5" /> CSV Template
                        </a>
                    </div>
                    <form @submit.prevent="submitBelinaCsv" class="space-y-3">
                        <input
                            type="file"
                            accept=".csv,text/csv"
                            required
                            @change="(e) => handleFileSelect('belina', e)"
                            class="block w-full text-xs text-slate-700"
                        />
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="submit"
                                :disabled="belinaCsvForm.processing || !belinaCsvForm.csv_file"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                            >
                                <Upload class="h-3.5 w-3.5" /> Import Belina Employees CSV
                            </button>
                            <a
                                href="/admin/integrations/export/belina-employees"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50"
                            >
                                <Download class="h-3.5 w-3.5" /> Export HR Directory CSV
                            </a>
                        </div>
                    </form>
                </section>

                <!-- Sage Pastel Vendors & Approved Invoices CSV Bridge -->
                <section class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <h2 class="text-sm font-bold text-slate-900">Sage Pastel Finance Bridge (CSV)</h2>
                        <a
                            href="/admin/integrations/templates/sage-vendors"
                            class="inline-flex items-center gap-1 text-xs font-semibold text-[#0E7C66] hover:underline"
                        >
                            <Download class="h-3.5 w-3.5" /> Vendor Template
                        </a>
                    </div>
                    <form @submit.prevent="submitSageCsv" class="space-y-3">
                        <input
                            type="file"
                            accept=".csv,text/csv"
                            required
                            @change="(e) => handleFileSelect('sage', e)"
                            class="block w-full text-xs text-slate-700"
                        />
                        <div class="flex flex-wrap gap-2">
                            <button
                                type="submit"
                                :disabled="sageCsvForm.processing || !sageCsvForm.csv_file"
                                class="inline-flex items-center gap-1.5 rounded-lg bg-slate-900 px-3.5 py-2 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                            >
                                <Upload class="h-3.5 w-3.5" /> Import Sage Vendors CSV
                            </button>
                            <a
                                href="/admin/integrations/export/ap-invoices?system=sage_pastel"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50"
                            >
                                <Download class="h-3.5 w-3.5" /> Export Approved AP Invoices CSV
                            </a>
                        </div>
                    </form>
                </section>
            </div>

            <!-- Webhook Delivery Log & External Mappings -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <section class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-3">
                    <h2 class="text-sm font-bold text-slate-900">Webhook Delivery Log</h2>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-xs">
                            <thead class="bg-slate-50 font-semibold text-slate-600 uppercase">
                                <tr>
                                    <th class="px-3 py-2">Webhook / Event</th>
                                    <th class="px-3 py-2">Status</th>
                                    <th class="px-3 py-2">HTTP</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="d in deliveries" :key="d.id">
                                    <td class="px-3 py-2">
                                        <p class="font-semibold text-slate-900">{{ d.webhook_name }}</p>
                                        <p class="font-mono text-[11px] text-slate-500">{{ d.event_type }}</p>
                                    </td>
                                    <td class="px-3 py-2">
                                        <span
                                            class="rounded-full px-2 py-0.5 text-[11px] font-semibold"
                                            :class="
                                                d.status === 'delivered'
                                                    ? 'bg-emerald-100 text-emerald-800'
                                                    : d.status === 'blocked_airgap'
                                                      ? 'bg-amber-100 text-amber-800'
                                                      : 'bg-slate-100 text-slate-700'
                                            "
                                        >
                                            {{ d.status }}
                                        </span>
                                    </td>
                                    <td class="px-3 py-2 font-mono">{{ d.response_code ?? '—' }}</td>
                                </tr>
                                <tr v-if="deliveries.length === 0">
                                    <td colspan="3" class="px-3 py-4 text-center text-slate-500">No webhook deliveries recorded yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-3">
                    <div class="flex items-center gap-2">
                        <Link2 class="h-4 w-4 text-[#0E7C66]" />
                        <h2 class="text-sm font-bold text-slate-900">External ERP ID Mappings ({{ mappings.length }})</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-left text-xs">
                            <thead class="bg-slate-50 font-semibold text-slate-600 uppercase">
                                <tr>
                                    <th class="px-3 py-2">System</th>
                                    <th class="px-3 py-2">Entity</th>
                                    <th class="px-3 py-2">External ID</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <tr v-for="m in mappings" :key="m.id">
                                    <td class="px-3 py-2 font-mono font-semibold text-slate-800">{{ m.external_system }}</td>
                                    <td class="px-3 py-2 text-slate-600">{{ m.entity_type }}</td>
                                    <td class="px-3 py-2 font-mono text-slate-900">{{ m.external_id }}</td>
                                </tr>
                                <tr v-if="mappings.length === 0">
                                    <td colspan="3" class="px-3 py-4 text-center text-slate-500">No ERP mappings created yet.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
