<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    Building2,
    CheckCircle2,
    Clock,
    Cpu,
    FileCheck2,
    HardDriveDownload,
    KeyRound,
    Lock,
    Mail,
    MapPin,
    Phone,
    Server,
    ShieldCheck,
    UserPlus,
    WifiOff,
} from '@lucide/vue';
import type { SharedProps } from '@/types/betterdoc';

const props = defineProps<{
    mode: 'portal' | 'demo';
}>();

const page = usePage<{ props: SharedProps }>();
const shared = computed(() => page.props as unknown as SharedProps);
const branding = computed(() => shared.value.branding);
const flashSuccess = computed(() => shared.value.flash?.success ?? null);
const selfRegistrationEnabled = computed(() => shared.value.app?.self_registration_enabled ?? true);

const hasSupportDetails = computed(
    () => Boolean(branding.value.support_email || branding.value.support_phone || branding.value.support_hours || branding.value.address),
);

const demoSubmitted = ref(false);

const demoForm = useForm({
    name: '',
    organisation: '',
    work_email: '',
    phone: '',
    message: '',
});

function submitDemoRequest(): void {
    demoForm.post('/demo-request', {
        preserveScroll: true,
        onSuccess: () => {
            demoSubmitted.value = true;
            demoForm.reset();
        },
    });
}

const featureCards = [
    {
        icon: WifiOff,
        title: 'Air-Gapped & Offline First',
        body: 'Runs with zero internet dependency. Bundled local typography, offline antivirus/OCR, local PWA scan queues, and LAN WebSockets.',
    },
    {
        icon: ShieldCheck,
        title: 'Bell-LaPadula & Database RLS',
        body: 'Clearance levels and compartments are enforced inside PostgreSQL row policies so unauthorized files cannot be searched, read, or inferred.',
    },
    {
        icon: FileCheck2,
        title: 'PAdES Signatures & Offline Verify',
        body: 'Built-in X.509 internal CA issues certificates and seals PDFs with QR codes verifiable on LAN without third-party cloud signers.',
    },
    {
        icon: Lock,
        title: 'Malware Quarantine, CDR & E2EE',
        body: 'Resumable 200MB+ uploads pass antivirus checks, Content Disarm & Reconstruction, and XChaCha20-Poly1305 streaming encryption.',
    },
    {
        icon: Cpu,
        title: 'Local AI, Finance AP & HR Vault',
        body: 'On-premise RAG queries enforce session high-water marks alongside 3-way PO matching, ZIMRA e-invoicing, and subject-shielded HR files.',
    },
    {
        icon: HardDriveDownload,
        title: '3-2-1 Backups & <2h Restore',
        body: 'Automated encrypted snapshots combine PostgreSQL WAL state and object storage with timed clean-machine restore verification.',
    },
];

const processSteps = [
    { step: '01', title: 'Select Organisational Profile', text: 'Configure Corporate, Government, Financial, or Defence rules, clearance ladder, and local branding in minutes.' },
    { step: '02', title: 'Capture, Scan & Sanitize', text: 'Ingest desktop uploads, multi-page scanner batches, and branch files through AV, CDR, and OCR.' },
    { step: '03', title: 'Classify, Route & Sign', text: 'Enforce permissions, trigger BPMN approval workflows, apply redactions, and seal PAdES signatures.' },
    { step: '04', title: 'Search, Audit & Recover', text: 'Query records in under a second, generate signed compliance bundles, and verify <2h backups.' },
];

const comparisonRows = [
    { capability: 'Operation without internet access', betterdoc: 'Full functionality (LAN / Air-gapped)', typical: 'Unavailable or degraded' },
    { capability: 'Database-level row isolation', betterdoc: 'PostgreSQL RLS + clearance ceilings', typical: 'Application-layer filters only' },
    { capability: 'External CDNs, fonts & trackers', betterdoc: 'Zero external requests', typical: 'Third-party telemetry & fonts' },
    { capability: 'Audit log tamper verification', betterdoc: 'SHA-256 hash chain + HMAC checkpoints', typical: 'Mutable application logs' },
    { capability: 'On-premise AI & PAdES signing', betterdoc: 'Local models & internal PKI / HSM', typical: 'External cloud APIs required' },
];
</script>

<template>
    <Head :title="mode === 'demo' ? 'Offline-First Document Management' : branding.welcome_title" />

    <div
        class="flex min-h-screen flex-col bg-[#F7F8FA] text-[#0F172A] antialiased"
        :style="{
            '--brand-primary': branding.primary_color,
            '--brand-primary-fg': branding.primary_fg,
            '--brand-accent': branding.accent_color,
            '--brand-accent-fg': branding.accent_fg,
        }"
    >
        <!-- TOP BRAND ACCENT RULE -->
        <div class="h-1 w-full shrink-0" :style="{ backgroundColor: 'var(--brand-primary)' }" />

        <!-- HEADER -->
        <header class="sticky top-0 z-20 border-b border-slate-200/90 bg-white/95 backdrop-blur-xs">
            <div class="mx-auto flex max-w-[1180px] flex-wrap items-center justify-between gap-4 px-5 py-3.5">
                <div class="flex items-center gap-3">
                    <img
                        v-if="branding.logo_url"
                        :src="branding.logo_url"
                        :alt="branding.name"
                        class="h-10 w-auto max-w-36 object-contain"
                    />
                    <div
                        v-else
                        class="flex h-10 w-10 items-center justify-center rounded-xl font-bold shadow-xs"
                        :style="{ backgroundColor: 'var(--brand-primary)', color: 'var(--brand-primary-fg)' }"
                    >
                        <ShieldCheck class="h-5 w-5" />
                    </div>
                    <div>
                        <p class="text-base font-bold tracking-tight text-[#0F172A]">{{ branding.name }}</p>
                        <p class="text-xs text-slate-600">{{ branding.tagline }}</p>
                    </div>
                </div>

                <nav aria-label="Header actions" class="flex flex-wrap items-center gap-2.5">
                    <template v-if="mode === 'demo'">
                        <a
                            href="#capabilities"
                            class="hidden rounded-lg px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 md:inline-block"
                        >
                            Capabilities
                        </a>
                        <a
                            href="#architecture"
                            class="hidden rounded-lg px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100 md:inline-block"
                        >
                            Architecture
                        </a>
                        <a
                            href="#book-demo"
                            class="rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-[#0F172A] transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A] motion-reduce:transition-none sm:text-sm"
                        >
                            Book a demo
                        </a>
                        <Link
                            v-if="selfRegistrationEnabled"
                            href="/register"
                            class="inline-flex items-center gap-1.5 rounded-lg border border-[#0E7C66]/40 bg-[#DDF3EC] px-3.5 py-2 text-xs font-semibold text-[#0F172A] transition hover:bg-[#caeade] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A] motion-reduce:transition-none sm:text-sm"
                        >
                            <UserPlus class="h-4 w-4 text-[#0E7C66]" />
                            <span>Create Organisation</span>
                        </Link>
                    </template>
                    <Link
                        href="/login"
                        class="inline-flex items-center gap-2 rounded-lg px-4 py-2 text-xs font-semibold shadow-xs transition hover:opacity-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A] motion-reduce:transition-none sm:text-sm"
                        :style="{ backgroundColor: 'var(--brand-primary)', color: 'var(--brand-primary-fg)' }"
                    >
                        <Lock class="h-4 w-4" />
                        <span>Sign in</span>
                    </Link>
                </nav>
            </div>
        </header>

        <!-- MODE 1: INTERNAL ORGANISATION PORTAL -->
        <main
            v-if="mode === 'portal'"
            class="mx-auto flex w-full max-w-[1180px] flex-1 flex-col justify-center px-5 py-10 md:py-16"
        >
            <!-- Optional Staff Notice Banner -->
            <div
                v-if="branding.notice_enabled && branding.notice_text"
                role="status"
                class="mb-7 flex items-start gap-3 rounded-[14px] border border-amber-300 bg-amber-50 p-4 text-sm text-[#0F172A] shadow-xs"
            >
                <AlertTriangle class="mt-0.5 h-5 w-5 shrink-0 text-[#B45309]" />
                <div>
                    <p class="font-bold">Notice</p>
                    <p class="mt-0.5 text-slate-800">{{ branding.notice_text }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-7 lg:grid-cols-12">
                <!-- Primary Welcome Card -->
                <section class="relative overflow-hidden rounded-[14px] border border-slate-200 bg-white p-8 shadow-sm sm:p-10 lg:col-span-7 flex flex-col justify-between">
                    <div>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-[#DDF3EC] px-3 py-1 text-xs font-bold text-[#0F172A]">
                                <ShieldCheck class="h-3.5 w-3.5 text-[#0E7C66]" />
                                {{ branding.short_name }}
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-medium text-slate-600">
                                <WifiOff class="h-3.5 w-3.5 text-slate-500" />
                                Local Network Portal
                            </span>
                        </div>

                        <h1 class="mt-5 text-3xl font-bold tracking-tight text-[#0F172A] sm:text-4xl">
                            {{ branding.welcome_title }}
                        </h1>

                        <p class="mt-4 max-w-2xl text-base leading-relaxed text-slate-700">
                            {{ branding.welcome_text }}
                        </p>
                    </div>

                    <div class="mt-8 space-y-6">
                        <div class="flex flex-wrap items-center gap-3">
                            <Link
                                href="/login"
                                class="inline-flex items-center gap-2.5 rounded-xl px-7 py-3.5 text-base font-semibold shadow-sm transition hover:opacity-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A] motion-reduce:transition-none"
                                :style="{ backgroundColor: 'var(--brand-primary)', color: 'var(--brand-primary-fg)' }"
                            >
                                <Lock class="h-4 w-4" />
                                <span>Sign in</span>
                                <ArrowRight class="h-5 w-5" />
                            </Link>
                        </div>

                        <div class="grid grid-cols-1 gap-3 border-t border-slate-100 pt-5 text-xs text-slate-600 sm:grid-cols-3">
                            <div class="flex items-center gap-2">
                                <KeyRound class="h-4 w-4 shrink-0 text-[#0E7C66]" />
                                <span>Role &amp; clearance access</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <FileCheck2 class="h-4 w-4 shrink-0 text-[#0E7C66]" />
                                <span>Verifiable PAdES seals</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <ShieldCheck class="h-4 w-4 shrink-0 text-[#0E7C66]" />
                                <span>Tamper-evident audit trail</span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Quick Support & Desk Card -->
                <aside class="rounded-[14px] border border-slate-200 bg-white p-7 shadow-sm sm:p-8 lg:col-span-5 flex flex-col justify-between space-y-6">
                    <div class="space-y-5">
                        <div class="border-b border-slate-100 pb-4">
                            <h2 class="text-base font-bold text-[#0F172A]">Help Desk &amp; Support Information</h2>
                            <p class="mt-1 text-xs text-slate-500">
                                Contact your internal systems administrator for account onboarding, clearances, or MFA resets.
                            </p>
                        </div>

                        <dl v-if="hasSupportDetails" class="space-y-4 text-sm">
                            <div v-if="branding.support_email" class="flex items-start gap-3">
                                <div class="rounded-lg bg-[#DDF3EC] p-2 text-[#0E7C66]">
                                    <Mail class="h-4 w-4 shrink-0" />
                                </div>
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Email</dt>
                                    <dd class="font-medium text-[#0F172A]">{{ branding.support_email }}</dd>
                                </div>
                            </div>

                            <div v-if="branding.support_phone" class="flex items-start gap-3">
                                <div class="rounded-lg bg-[#DDF3EC] p-2 text-[#0E7C66]">
                                    <Phone class="h-4 w-4 shrink-0" />
                                </div>
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Phone</dt>
                                    <dd class="font-medium text-[#0F172A]">{{ branding.support_phone }}</dd>
                                </div>
                            </div>

                            <div v-if="branding.support_hours" class="flex items-start gap-3">
                                <div class="rounded-lg bg-[#DDF3EC] p-2 text-[#0E7C66]">
                                    <Clock class="h-4 w-4 shrink-0" />
                                </div>
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Support Hours</dt>
                                    <dd class="font-medium text-[#0F172A]">{{ branding.support_hours }}</dd>
                                </div>
                            </div>

                            <div v-if="branding.address" class="flex items-start gap-3">
                                <div class="rounded-lg bg-[#DDF3EC] p-2 text-[#0E7C66]">
                                    <MapPin class="h-4 w-4 shrink-0" />
                                </div>
                                <div>
                                    <dt class="text-[11px] font-semibold uppercase tracking-wider text-slate-500">Location</dt>
                                    <dd class="font-medium text-[#0F172A]">{{ branding.address }}</dd>
                                </div>
                            </div>
                        </dl>

                        <div v-else class="rounded-xl border border-dashed border-slate-300 bg-slate-50 p-4 text-xs leading-relaxed text-slate-600">
                            Support email, telephone extension, and office location have not been configured yet. A SystemAdmin can populate these details in <strong>Settings &gt; Branding</strong>.
                        </div>
                    </div>

                    <div class="rounded-xl bg-[#0F172A] p-4 text-xs text-slate-300">
                        <p class="font-semibold text-white">Security Advisory</p>
                        <p class="mt-1 text-slate-400">
                            Activity inside {{ branding.short_name }} is logged to the hash-chained compliance ledger under organisational policy.
                        </p>
                    </div>
                </aside>
            </div>
        </main>

        <!-- MODE 2: SALES DEMONSTRATION LANDING PAGE -->
        <main v-else class="mx-auto w-full max-w-[1180px] flex-1 space-y-16 px-5 py-12">
            <!-- Hero -->
            <section class="rounded-[14px] border border-slate-200 bg-white p-7 shadow-sm md:p-11">
                <div class="grid grid-cols-1 gap-8 lg:grid-cols-12 lg:items-center">
                    <div class="lg:col-span-7">
                        <span class="inline-flex items-center gap-2 rounded-full bg-[#DDF3EC] px-3.5 py-1 text-xs font-bold text-[#0F172A]">
                            <WifiOff class="h-3.5 w-3.5 text-[#0E7C66]" />
                            Sovereign On-Premise &amp; LAN-First DMS
                        </span>
                        <h1 class="mt-4 text-3xl font-bold tracking-tight text-[#0F172A] sm:text-5xl sm:leading-[1.12]">
                            Document management that works when the internet doesn't.
                        </h1>
                        <p class="mt-4 text-base leading-relaxed text-slate-700 sm:text-lg">
                            Built for ministries, regulated banks, defence commands, and distributed enterprises requiring PostgreSQL row-level isolation, verifiable PAdES signatures, AP/contracts automation, and instant recovery.
                        </p>
                        <div class="mt-7 flex flex-wrap gap-3">
                            <Link
                                v-if="selfRegistrationEnabled"
                                href="/register"
                                class="inline-flex items-center gap-2 rounded-xl px-6 py-3.5 text-sm font-semibold shadow-sm transition hover:opacity-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A] motion-reduce:transition-none"
                                :style="{ backgroundColor: 'var(--brand-primary)', color: 'var(--brand-primary-fg)' }"
                            >
                                <Building2 class="h-4 w-4" />
                                <span>Create Organisation (Sign up)</span>
                                <ArrowRight class="h-4 w-4" />
                            </Link>
                            <Link
                                href="/login"
                                class="inline-flex items-center gap-2 rounded-xl border border-slate-300 bg-white px-6 py-3.5 text-sm font-semibold text-[#0F172A] transition hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A] motion-reduce:transition-none"
                            >
                                <Lock class="h-4 w-4" />
                                <span>Sign in</span>
                            </Link>
                            <a
                                href="#book-demo"
                                class="inline-flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-5 py-3.5 text-sm font-semibold text-slate-800 transition hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A] motion-reduce:transition-none"
                            >
                                Book a demo
                            </a>
                        </div>
                    </div>

                    <!-- Executive Infrastructure Snapshot Card -->
                    <aside class="lg:col-span-5">
                        <div class="rounded-[14px] bg-[#0F172A] p-6 text-slate-100 shadow-lg space-y-5">
                            <div class="flex items-center justify-between border-b border-slate-800 pb-3">
                                <span class="text-xs font-bold tracking-wider text-emerald-400 uppercase">Sovereign Vault Guarantees</span>
                                <span class="rounded bg-slate-800 px-2 py-0.5 font-mono text-[10px] text-slate-300">OFFLINE READY</span>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-xl border border-slate-800 bg-slate-900/90 p-3.5">
                                    <p class="font-mono text-xl font-bold text-white">0</p>
                                    <p class="mt-1 text-xs text-slate-400">External CDNs, trackers or fonts</p>
                                </div>
                                <div class="rounded-xl border border-slate-800 bg-slate-900/90 p-3.5">
                                    <p class="font-mono text-xl font-bold text-emerald-400">RLS</p>
                                    <p class="mt-1 text-xs text-slate-400">PostgreSQL MAC clearance policies</p>
                                </div>
                                <div class="rounded-xl border border-slate-800 bg-slate-900/90 p-3.5">
                                    <p class="font-mono text-xl font-bold text-amber-400">PAdES</p>
                                    <p class="mt-1 text-xs text-slate-400">Internal X.509 CA + offline verify</p>
                                </div>
                                <div class="rounded-xl border border-slate-800 bg-slate-900/90 p-3.5">
                                    <p class="font-mono text-xl font-bold text-white">&lt; 2h</p>
                                    <p class="mt-1 text-xs text-slate-400">Timed 3-2-1 encrypted DR target</p>
                                </div>
                            </div>

                            <div class="rounded-xl border border-emerald-900/60 bg-emerald-950/40 p-3.5 text-xs text-slate-300 leading-relaxed">
                                Deploy on-premise and onboard your own Corporate, Government, Financial, or Defence portal in seconds — complete with custom logos, roles, and granular permissions.
                            </div>
                        </div>
                    </aside>
                </div>
            </section>

            <!-- 6 Feature Cards -->
            <section id="capabilities" aria-labelledby="features-heading" class="space-y-6">
                <div>
                    <h2 id="features-heading" class="text-2xl font-bold text-[#0F172A]">Core Capabilities</h2>
                    <p class="mt-1 text-sm text-slate-600">Engineered for zero-internet resilience, strict regulatory compliance, and sub-second search.</p>
                </div>
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 lg:grid-cols-3">
                    <article
                        v-for="card in featureCards"
                        :key="card.title"
                        class="rounded-[14px] border border-slate-200 bg-white p-6 shadow-xs transition hover:border-[#0E7C66]/50 motion-reduce:transition-none"
                    >
                        <div class="inline-flex rounded-xl bg-[#DDF3EC] p-2.5 text-[#0E7C66]">
                            <component :is="card.icon" class="h-5 w-5" />
                        </div>
                        <h3 class="mt-4 text-base font-bold text-[#0F172A]">{{ card.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ card.body }}</p>
                    </article>
                </div>
            </section>

            <!-- 4-Step Process -->
            <section aria-labelledby="process-heading" class="space-y-6">
                <h2 id="process-heading" class="text-2xl font-bold text-[#0F172A]">How It Operates in Four Steps</h2>
                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="item in processSteps"
                        :key="item.step"
                        class="rounded-[14px] border border-slate-200 bg-white p-6 shadow-xs"
                    >
                        <span
                            class="inline-flex rounded-lg px-2.5 py-1 font-mono text-xs font-bold"
                            :style="{ backgroundColor: 'var(--brand-accent)', color: 'var(--brand-accent-fg)' }"
                        >
                            STEP {{ item.step }}
                        </span>
                        <h3 class="mt-3 text-base font-bold text-[#0F172A]">{{ item.title }}</h3>
                        <p class="mt-2 text-sm leading-relaxed text-slate-600">{{ item.text }}</p>
                    </div>
                </div>
            </section>

            <!-- Comparison Table -->
            <section id="architecture" aria-labelledby="comparison-heading" class="space-y-6">
                <h2 id="comparison-heading" class="text-2xl font-bold text-[#0F172A]">Architecture Comparison</h2>
                <div class="overflow-x-auto rounded-[14px] border border-slate-200 bg-white shadow-xs">
                    <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                        <thead class="bg-slate-50 text-xs font-bold uppercase tracking-wider text-slate-600">
                            <tr>
                                <th class="px-6 py-4">Requirement</th>
                                <th class="px-6 py-4 text-[#0E7C66]">BetterDoc DMS</th>
                                <th class="px-6 py-4">Typical cloud-first DMS</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <tr v-for="row in comparisonRows" :key="row.capability">
                                <td class="px-6 py-4 font-semibold text-[#0F172A]">{{ row.capability }}</td>
                                <td class="px-6 py-4 font-medium text-[#0E7C66]">{{ row.betterdoc }}</td>
                                <td class="px-6 py-4 text-slate-600">{{ row.typical }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <!-- Deployment Options -->
            <section aria-labelledby="deploy-heading" class="space-y-6">
                <h2 id="deploy-heading" class="text-2xl font-bold text-[#0F172A]">Deployment Options</h2>
                <div class="grid grid-cols-1 gap-5 md:grid-cols-3">
                    <div class="rounded-[14px] border border-slate-200 bg-white p-6 shadow-xs">
                        <Server class="h-5 w-5 text-[#0E7C66]" />
                        <h3 class="mt-3 text-base font-bold">Air-Gapped On-Premise</h3>
                        <p class="mt-2 text-sm text-slate-600">Single-server appliance or multi-node k3s cluster deployed from signed offline USB media.</p>
                    </div>
                    <div class="rounded-[14px] border border-slate-200 bg-white p-6 shadow-xs">
                        <Server class="h-5 w-5 text-[#0E7C66]" />
                        <h3 class="mt-3 text-base font-bold">LAN + Controlled Sync</h3>
                        <p class="mt-2 text-sm text-slate-600">Replicates encrypted low-classification folders between offices while sealing higher records locally.</p>
                    </div>
                    <div class="rounded-[14px] border border-slate-200 bg-white p-6 shadow-xs">
                        <Server class="h-5 w-5 text-[#0E7C66]" />
                        <h3 class="mt-3 text-base font-bold">Sovereign Private Cloud</h3>
                        <p class="mt-2 text-sm text-slate-600">Standard S3/MinIO object storage and PostgreSQL HA inside dedicated private VPCs.</p>
                    </div>
                </div>
            </section>

            <!-- Book a Demo Form -->
            <section id="book-demo" aria-labelledby="demo-heading" class="rounded-[14px] border border-slate-200 bg-white p-8 shadow-xs">
                <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                    <div class="lg:col-span-5 space-y-3">
                        <h2 id="demo-heading" class="text-2xl font-bold text-[#0F172A]">Book a demo</h2>
                        <p class="text-sm leading-relaxed text-slate-600">
                            Request an offline walkthrough tailored to your organisation profile, clearance model, and backup requirements.
                        </p>
                        <div
                            v-if="flashSuccess || demoSubmitted"
                            role="status"
                            class="flex items-start gap-2 rounded-xl border border-emerald-300 bg-[#DDF3EC] p-4 text-sm font-medium text-[#0F172A]"
                        >
                            <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0 text-[#0E7C66]" />
                            <span>{{ flashSuccess ?? 'Thank you. Your demonstration request has been recorded.' }}</span>
                        </div>
                    </div>

                    <form @submit.prevent="submitDemoRequest" class="space-y-4 lg:col-span-7">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div>
                                <label for="demo-name" class="block text-xs font-semibold text-slate-700">Full Name *</label>
                                <input
                                    id="demo-name"
                                    v-model="demoForm.name"
                                    type="text"
                                    required
                                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A]"
                                />
                                <p v-if="demoForm.errors.name" class="mt-1 text-xs text-red-700">{{ demoForm.errors.name }}</p>
                            </div>

                            <div>
                                <label for="demo-org" class="block text-xs font-semibold text-slate-700">Organisation *</label>
                                <input
                                    id="demo-org"
                                    v-model="demoForm.organisation"
                                    type="text"
                                    required
                                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A]"
                                />
                                <p v-if="demoForm.errors.organisation" class="mt-1 text-xs text-red-700">{{ demoForm.errors.organisation }}</p>
                            </div>

                            <div>
                                <label for="demo-email" class="block text-xs font-semibold text-slate-700">Work Email *</label>
                                <input
                                    id="demo-email"
                                    v-model="demoForm.work_email"
                                    type="email"
                                    required
                                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A]"
                                />
                                <p v-if="demoForm.errors.work_email" class="mt-1 text-xs text-red-700">{{ demoForm.errors.work_email }}</p>
                            </div>

                            <div>
                                <label for="demo-phone" class="block text-xs font-semibold text-slate-700">Phone (optional)</label>
                                <input
                                    id="demo-phone"
                                    v-model="demoForm.phone"
                                    type="text"
                                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A]"
                                />
                                <p v-if="demoForm.errors.phone" class="mt-1 text-xs text-red-700">{{ demoForm.errors.phone }}</p>
                            </div>
                        </div>

                        <div>
                            <label for="demo-message" class="block text-xs font-semibold text-slate-700">Requirements or Message (optional)</label>
                            <textarea
                                id="demo-message"
                                v-model="demoForm.message"
                                rows="3"
                                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A]"
                            />
                            <p v-if="demoForm.errors.message" class="mt-1 text-xs text-red-700">{{ demoForm.errors.message }}</p>
                        </div>

                        <button
                            type="submit"
                            :disabled="demoForm.processing"
                            class="inline-flex items-center gap-2 rounded-xl px-6 py-3 text-sm font-semibold shadow-xs transition hover:opacity-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#0F172A] disabled:opacity-50 motion-reduce:transition-none"
                            :style="{ backgroundColor: 'var(--brand-primary)', color: 'var(--brand-primary-fg)' }"
                        >
                            Submit Demo Request
                        </button>
                    </form>
                </div>
            </section>
        </main>

        <!-- FOOTER -->
        <footer class="mt-auto border-t border-slate-200 bg-white">
            <div class="mx-auto flex max-w-[1180px] flex-wrap items-center justify-between gap-4 px-5 py-5 text-xs text-slate-600">
                <div>
                    <span class="font-semibold text-[#0F172A]">{{ branding.name }}</span>
                    <span v-if="branding.address"> · {{ branding.address }}</span>
                </div>
                <div v-if="branding.show_powered_by">
                    Powered by BetterDoc DMS
                </div>
            </div>
        </footer>
    </div>
</template>
