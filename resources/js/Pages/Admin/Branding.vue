<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    Inbox,
    Palette,
    RotateCcw,
    ShieldCheck,
    Sparkles,
    Upload,
    UserCog,
} from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import type { BrandingPayload } from '@/types/betterdoc';

interface PresetItem {
    profile_key: string;
    label: string;
    name: string;
    short_name: string;
    tagline: string;
    primary_color: string;
    accent_color: string;
    welcome_title: string;
    welcome_text: string;
    notice_text: string;
    notice_enabled: boolean;
    support_email: string;
    support_phone: string;
    support_hours: string;
    address: string;
    website: string;
    show_powered_by: boolean;
}

interface DemoRequestRow {
    id: string;
    name: string;
    organisation: string;
    work_email: string;
    phone: string | null;
    message: string | null;
    status: string;
    created_at: string | null;
}

const props = defineProps<{
    currentBranding: BrandingPayload;
    presets: PresetItem[];
    landingModeEnv: string;
    demoRequests: DemoRequestRow[];
}>();

const activePreviewTab = ref<'portal' | 'login'>('portal');

const form = useForm<{
    name: string;
    short_name: string;
    tagline: string;
    primary_color: string;
    accent_color: string;
    welcome_title: string;
    welcome_text: string;
    notice_text: string;
    notice_enabled: boolean;
    support_email: string;
    support_phone: string;
    support_hours: string;
    address: string;
    website: string;
    show_powered_by: boolean;
    landing_mode: string;
    logo: File | null;
    logo_dark: File | null;
    favicon: File | null;
}>({
    name: props.currentBranding.name,
    short_name: props.currentBranding.short_name,
    tagline: props.currentBranding.tagline,
    primary_color: props.currentBranding.primary_color,
    accent_color: props.currentBranding.accent_color,
    welcome_title: props.currentBranding.welcome_title,
    welcome_text: props.currentBranding.welcome_text,
    notice_text: props.currentBranding.notice_text,
    notice_enabled: props.currentBranding.notice_enabled,
    support_email: props.currentBranding.support_email,
    support_phone: props.currentBranding.support_phone,
    support_hours: props.currentBranding.support_hours,
    address: props.currentBranding.address,
    website: props.currentBranding.website,
    show_powered_by: props.currentBranding.show_powered_by,
    landing_mode: props.currentBranding.landing_mode_setting ?? '',
    logo: null,
    logo_dark: null,
    favicon: null,
});

function linearChannel(c: number): number {
    const s = c / 255;
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
}

function luminance(hex: string): number {
    const m = /^#([0-9A-Fa-f]{6})$/.exec(hex.trim());
    if (!m) {
        return 0;
    }
    const v = m[1];
    return 0.2126 * linearChannel(parseInt(v.slice(0, 2), 16))
        + 0.7152 * linearChannel(parseInt(v.slice(2, 4), 16))
        + 0.0722 * linearChannel(parseInt(v.slice(4, 6), 16));
}

function contrastRatio(hexA: string, hexB: string): number {
    const la = luminance(hexA);
    const lb = luminance(hexB);
    return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

const livePrimaryFg = computed(() =>
    contrastRatio(form.primary_color, '#FFFFFF') >= contrastRatio(form.primary_color, '#0F172A') ? '#FFFFFF' : '#0F172A',
);
const livePrimaryButtonRatio = computed(() =>
    Number(contrastRatio(form.primary_color, livePrimaryFg.value).toFixed(2)),
);
const livePrimarySurfaceRatio = computed(() =>
    Number(contrastRatio(form.primary_color, '#F7F8FA').toFixed(2)),
);
const liveAccentFg = computed(() =>
    contrastRatio(form.accent_color, '#FFFFFF') >= contrastRatio(form.accent_color, '#0F172A') ? '#FFFFFF' : '#0F172A',
);
const liveAccentButtonRatio = computed(() =>
    Number(contrastRatio(form.accent_color, liveAccentFg.value).toFixed(2)),
);

const liveWarnings = computed(() => {
    const list: string[] = [];
    if (livePrimaryButtonRatio.value < 4.5) {
        list.push(`Primary button text contrast is ${livePrimaryButtonRatio.value}:1 (< 4.5:1 WCAG AA).`);
    }
    if (livePrimarySurfaceRatio.value < 4.5) {
        list.push(`Primary colour on light background (#F7F8FA) is ${livePrimarySurfaceRatio.value}:1 (< 4.5:1 for small text/links).`);
    }
    if (liveAccentButtonRatio.value < 4.5) {
        list.push(`Accent badge text contrast is ${liveAccentButtonRatio.value}:1 (< 4.5:1 WCAG AA).`);
    }
    return list;
});

function saveBranding(): void {
    form.post('/settings/branding', {
        forceFormData: true,
        preserveScroll: true,
    });
}

function applyPreset(key: string): void {
    router.post('/settings/branding/preset', { profile_key: key }, {
        onSuccess: () => router.reload(),
    });
}

function resetBranding(): void {
    router.post('/settings/branding/reset', {}, {
        onSuccess: () => router.reload(),
    });
}
</script>

<template>
    <Head title="Settings — Branding" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Settings &gt; Organisation Branding</h1>
                    <p class="text-xs text-slate-600">
                        Configure name, logo, colours, landing mode, help desk info &amp; notices across &quot;/&quot;, Login, Emails &amp; Watermarks with zero code changes.
                    </p>
                </div>
                <button
                    type="button"
                    @click="resetBranding"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50"
                >
                    <RotateCcw class="h-3.5 w-3.5" /> Reset to defaults
                </button>
            </div>

            <!-- Organisation Setup Next Step Banner -->
            <div class="flex flex-wrap items-center justify-between gap-4 rounded-[14px] border border-emerald-200 bg-[#DDF3EC] p-4 text-xs text-[#0F172A]">
                <div class="flex items-start gap-3">
                    <CheckCircle2 class="mt-0.5 h-5 w-5 shrink-0 text-[#0E7C66]" />
                    <div>
                        <p class="font-bold">Organisation Onboarding Workflow: Step 1 Branding &rarr; Step 2 Users, Roles &amp; Permissions</p>
                        <p class="mt-0.5 text-slate-700">
                            Save your Company Name, Support Contacts, Logo &amp; Brand Colours below, then open Users &amp; Roles to create departments, staff accounts, roles &amp; permissions.
                        </p>
                    </div>
                </div>
                <Link
                    href="/admin/users"
                    class="inline-flex items-center gap-2 rounded-lg bg-[#0E7C66] px-4 py-2 font-semibold text-white hover:opacity-95"
                >
                    <UserCog class="h-4 w-4" />
                    <span>Next: Create Users &amp; Assign Roles</span>
                    <ArrowRight class="h-3.5 w-3.5" />
                </Link>
            </div>

            <!-- First-Run Setup Wizard Presets -->
            <div class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-3">
                <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                    <Sparkles class="h-4 w-4 text-[#0E7C66]" />
                    First-Run Profile Wizard Presets
                </div>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <button
                        v-for="p in presets"
                        :key="p.profile_key"
                        type="button"
                        @click="applyPreset(p.profile_key)"
                        class="flex flex-col items-start gap-1 rounded-xl border p-3.5 text-left transition hover:border-slate-400"
                        :class="currentBranding.profile_key === p.profile_key ? 'border-[#0E7C66] bg-[#DDF3EC]/50' : 'border-slate-200 bg-slate-50'"
                    >
                        <div class="flex w-full items-center justify-between">
                            <span class="text-xs font-bold text-slate-900">{{ p.label }}</span>
                            <span class="inline-flex gap-1">
                                <span class="h-3 w-3 rounded-full border border-white/60" :style="{ backgroundColor: p.primary_color }" />
                                <span class="h-3 w-3 rounded-full border border-white/60" :style="{ backgroundColor: p.accent_color }" />
                            </span>
                        </div>
                        <span class="text-[11px] text-slate-600">{{ p.name }}</span>
                    </button>
                </div>
            </div>

            <!-- Editor + Live Preview Grid -->
            <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
                <form @submit.prevent="saveBranding" class="space-y-5 rounded-[14px] border border-slate-200 bg-white p-6 shadow-xs xl:col-span-7">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <Palette class="h-4 w-4 text-[#0E7C66]" />
                        Organisation Identity, Colours &amp; Uploads
                    </div>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <label for="br-name" class="block text-xs font-semibold text-slate-700">Organisation Name *</label>
                            <input id="br-name" v-model="form.name" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
                            <p v-if="form.errors.name" class="mt-1 text-xs text-red-600">{{ form.errors.name }}</p>
                        </div>

                        <div>
                            <label for="br-short" class="block text-xs font-semibold text-slate-700">Short Name *</label>
                            <input id="br-short" v-model="form.short_name" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
                        </div>

                        <div class="sm:col-span-2">
                            <label for="br-tagline" class="block text-xs font-semibold text-slate-700">Tagline *</label>
                            <input id="br-tagline" v-model="form.tagline" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
                        </div>

                        <div>
                            <label for="br-mode" class="block text-xs font-semibold text-slate-700">
                                Public Landing Mode (<code class="font-mono">.env={{ landingModeEnv }}</code>)
                            </label>
                            <select id="br-mode" v-model="form.landing_mode" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs">
                                <option value="">Inherit .env (LANDING_MODE={{ landingModeEnv }})</option>
                                <option value="portal">portal — Branded Staff Welcome Portal</option>
                                <option value="demo">demo — Full Sales Demo Landing Page</option>
                                <option value="off">off — Redirect "/" Directly to Login</option>
                            </select>
                        </div>

                        <div class="flex items-end pb-1">
                            <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <input v-model="form.show_powered_by" type="checkbox" class="rounded border-slate-300" />
                                Show &quot;Powered by BetterDoc DMS&quot; footer
                            </label>
                        </div>

                        <!-- Colours + WCAG Contrast -->
                        <div>
                            <label for="br-primary" class="block text-xs font-semibold text-slate-700">Primary Colour</label>
                            <div class="mt-1 flex items-center gap-2">
                                <input v-model="form.primary_color" type="color" aria-label="Pick primary colour" class="h-8 w-10 cursor-pointer rounded border border-slate-300" />
                                <input id="br-primary" v-model="form.primary_color" pattern="^#[0-9A-Fa-f]{6}$" required class="flex-1 rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono text-xs" />
                            </div>
                            <p class="mt-1 font-mono text-[11px] text-slate-500">
                                Button text {{ livePrimaryFg }} ({{ livePrimaryButtonRatio }}:1)
                            </p>
                        </div>

                        <div>
                            <label for="br-accent" class="block text-xs font-semibold text-slate-700">Accent Colour</label>
                            <div class="mt-1 flex items-center gap-2">
                                <input v-model="form.accent_color" type="color" aria-label="Pick accent colour" class="h-8 w-10 cursor-pointer rounded border border-slate-300" />
                                <input id="br-accent" v-model="form.accent_color" pattern="^#[0-9A-Fa-f]{6}$" required class="flex-1 rounded-lg border border-slate-300 px-2.5 py-1.5 font-mono text-xs" />
                            </div>
                            <p class="mt-1 font-mono text-[11px] text-slate-500">
                                Badge text {{ liveAccentFg }} ({{ liveAccentButtonRatio }}:1)
                            </p>
                        </div>
                    </div>

                    <!-- Contrast Status Box -->
                    <div
                        v-if="liveWarnings.length > 0"
                        class="flex items-start gap-2 rounded-xl border border-amber-300 bg-amber-50 p-3 text-xs text-amber-950"
                    >
                        <AlertTriangle class="mt-0.5 h-4 w-4 shrink-0 text-amber-700" />
                        <ul class="space-y-0.5">
                            <li v-for="w in liveWarnings" :key="w">{{ w }}</li>
                        </ul>
                    </div>
                    <div v-else class="flex items-center gap-2 rounded-xl border border-emerald-200 bg-[#DDF3EC] p-3 text-xs font-semibold text-[#0F172A]">
                        <CheckCircle2 class="h-4 w-4 text-[#0E7C66]" />
                        WCAG AA contrast (&ge; 4.5:1) verified across buttons and surfaces.
                    </div>

                    <!-- Welcome Copy & Notice -->
                    <div class="space-y-3 border-t border-slate-200 pt-4">
                        <div>
                            <label for="br-wtitle" class="block text-xs font-semibold text-slate-700">Welcome Title *</label>
                            <input id="br-wtitle" v-model="form.welcome_title" required class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
                        </div>
                        <div>
                            <label for="br-wtext" class="block text-xs font-semibold text-slate-700">Welcome Message</label>
                            <textarea id="br-wtext" v-model="form.welcome_text" rows="2" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
                        </div>
                        <div class="space-y-1.5">
                            <label class="flex items-center gap-2 text-xs font-semibold text-slate-700">
                                <input v-model="form.notice_enabled" type="checkbox" class="rounded border-slate-300" />
                                Display Staff Notice Banner on Portal
                            </label>
                            <input
                                v-model="form.notice_text"
                                placeholder="e.g. System maintenance Saturday 6pm"
                                class="w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs"
                            />
                        </div>
                    </div>

                    <!-- Uploads (PNG / JPG / Sanitized SVG <= 2MB) -->
                    <div class="grid grid-cols-1 gap-3 border-t border-slate-200 pt-4 sm:grid-cols-3">
                        <div>
                            <label for="br-logo" class="block text-xs font-semibold text-slate-700">Logo (PNG/JPG/SVG)</label>
                            <input id="br-logo" type="file" accept=".png,.jpg,.jpeg,.svg" @change="(e) => { form.logo = (e.target as HTMLInputElement).files?.[0] ?? null; }" class="mt-1 block w-full text-[11px]" />
                            <p v-if="form.errors.logo" class="mt-1 text-xs text-red-600">{{ form.errors.logo }}</p>
                        </div>
                        <div>
                            <label for="br-logo-dark" class="block text-xs font-semibold text-slate-700">Dark-Bg Logo</label>
                            <input id="br-logo-dark" type="file" accept=".png,.jpg,.jpeg,.svg" @change="(e) => { form.logo_dark = (e.target as HTMLInputElement).files?.[0] ?? null; }" class="mt-1 block w-full text-[11px]" />
                            <p v-if="form.errors.logo_dark" class="mt-1 text-xs text-red-600">{{ form.errors.logo_dark }}</p>
                        </div>
                        <div>
                            <label for="br-favicon" class="block text-xs font-semibold text-slate-700">Favicon</label>
                            <input id="br-favicon" type="file" accept=".png,.jpg,.jpeg,.svg" @change="(e) => { form.favicon = (e.target as HTMLInputElement).files?.[0] ?? null; }" class="mt-1 block w-full text-[11px]" />
                            <p v-if="form.errors.favicon" class="mt-1 text-xs text-red-600">{{ form.errors.favicon }}</p>
                        </div>
                    </div>

                    <!-- Support & Contact -->
                    <div class="grid grid-cols-1 gap-3 border-t border-slate-200 pt-4 sm:grid-cols-2">
                        <div>
                            <label for="br-email" class="block text-xs font-semibold text-slate-700">Support Email</label>
                            <input id="br-email" v-model="form.support_email" type="email" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
                        </div>
                        <div>
                            <label for="br-phone" class="block text-xs font-semibold text-slate-700">Support Phone</label>
                            <input id="br-phone" v-model="form.support_phone" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
                        </div>
                        <div>
                            <label for="br-hours" class="block text-xs font-semibold text-slate-700">Support Hours</label>
                            <input id="br-hours" v-model="form.support_hours" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
                        </div>
                        <div>
                            <label for="br-website" class="block text-xs font-semibold text-slate-700">Website</label>
                            <input id="br-website" v-model="form.website" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
                        </div>
                        <div class="sm:col-span-2">
                            <label for="br-address" class="block text-xs font-semibold text-slate-700">Address</label>
                            <input id="br-address" v-model="form.address" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-xs" />
                        </div>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-5 py-2.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                        >
                            <Upload class="h-3.5 w-3.5" /> Save &amp; Publish Branding
                        </button>
                    </div>
                </form>

                <!-- Live Preview Card -->
                <div class="space-y-4 xl:col-span-5">
                    <div class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold uppercase tracking-wider text-slate-600">Live Preview</span>
                            <div class="flex gap-1 rounded-lg bg-slate-100 p-1 text-[11px] font-semibold">
                                <button
                                    type="button"
                                    @click="activePreviewTab = 'portal'"
                                    class="rounded px-2.5 py-1"
                                    :class="activePreviewTab === 'portal' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600'"
                                >
                                    Portal "/"
                                </button>
                                <button
                                    type="button"
                                    @click="activePreviewTab = 'login'"
                                    class="rounded px-2.5 py-1"
                                    :class="activePreviewTab === 'login' ? 'bg-white text-slate-900 shadow-xs' : 'text-slate-600'"
                                >
                                    Login Page
                                </button>
                            </div>
                        </div>

                        <!-- Portal Live Mockup -->
                        <div v-if="activePreviewTab === 'portal'" class="overflow-hidden rounded-xl border border-slate-200 bg-[#F7F8FA]">
                            <div class="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-3">
                                <div class="flex items-center gap-2">
                                    <img v-if="currentBranding.logo_url" :src="currentBranding.logo_url" alt="" class="h-6 w-auto" />
                                    <div v-else class="flex h-6 w-6 items-center justify-center rounded" :style="{ backgroundColor: form.primary_color, color: livePrimaryFg }">
                                        <ShieldCheck class="h-3.5 w-3.5" />
                                    </div>
                                    <span class="text-xs font-bold text-[#0F172A]">{{ form.name }}</span>
                                </div>
                                <span class="rounded px-2.5 py-1 text-[11px] font-semibold" :style="{ backgroundColor: form.primary_color, color: livePrimaryFg }">
                                    Sign in
                                </span>
                            </div>

                            <div class="p-4 space-y-3">
                                <div v-if="form.notice_enabled && form.notice_text" class="rounded-lg border border-amber-300 bg-amber-50 px-3 py-2 text-[11px] text-[#0F172A]">
                                    <strong>Notice:</strong> {{ form.notice_text }}
                                </div>

                                <div class="rounded-xl border border-slate-200 bg-white p-4 space-y-2">
                                    <span class="inline-block rounded-full px-2 py-0.5 text-[10px] font-bold" :style="{ backgroundColor: form.accent_color, color: liveAccentFg }">
                                        {{ form.short_name }}
                                    </span>
                                    <h3 class="text-sm font-bold text-[#0F172A]">{{ form.welcome_title }}</h3>
                                    <p class="text-[11px] text-slate-600">{{ form.welcome_text }}</p>
                                    <div class="pt-1">
                                        <span class="inline-block rounded-lg px-3.5 py-1.5 text-xs font-semibold" :style="{ backgroundColor: form.primary_color, color: livePrimaryFg }">
                                            Sign in
                                        </span>
                                    </div>
                                </div>

                                <div class="flex items-center justify-between text-[10px] text-slate-500">
                                    <span>{{ form.support_email }} · {{ form.support_phone }}</span>
                                    <span v-if="form.show_powered_by">Powered by BetterDoc DMS</span>
                                </div>
                            </div>
                        </div>

                        <!-- Login Live Mockup -->
                        <div v-else class="rounded-xl bg-slate-950 p-5 text-white space-y-3">
                            <div class="text-[10px] font-semibold uppercase tracking-wider text-slate-400">
                                {{ form.name }} // PORTAL SIGN IN
                            </div>
                            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4 space-y-3">
                                <div class="flex items-center gap-2">
                                    <img v-if="currentBranding.logo_dark_url || currentBranding.logo_url" :src="currentBranding.logo_dark_url || currentBranding.logo_url || ''" alt="" class="h-7 w-auto" />
                                    <div>
                                        <p class="text-xs font-bold">Sign in to {{ form.short_name }}</p>
                                        <p class="text-[10px] text-slate-400">{{ form.tagline }}</p>
                                    </div>
                                </div>
                                <div class="h-7 rounded border border-slate-700 bg-slate-950" />
                                <div class="h-7 rounded border border-slate-700 bg-slate-950" />
                                <div class="rounded-lg py-1.5 text-center text-xs font-semibold" :style="{ backgroundColor: form.primary_color, color: livePrimaryFg }">
                                    Sign in securely
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Demo Requests Admin List -->
                    <div class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-3">
                        <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                            <Inbox class="h-4 w-4 text-[#0E7C66]" />
                            Submitted Demo Requests ({{ demoRequests.length }})
                        </div>
                        <div v-if="demoRequests.length === 0" class="text-xs text-slate-500">
                            No demonstration inquiries received yet.
                        </div>
                        <div v-else class="space-y-2 max-h-80 overflow-y-auto">
                            <div v-for="req in demoRequests" :key="req.id" class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs space-y-1">
                                <div class="flex items-center justify-between font-bold text-slate-900">
                                    <span>{{ req.name }} — {{ req.organisation }}</span>
                                    <span class="rounded bg-white px-1.5 py-0.5 font-mono text-[10px] text-slate-600 border border-slate-200">{{ req.status }}</span>
                                </div>
                                <div class="font-mono text-[11px] text-slate-700">{{ req.work_email }} <span v-if="req.phone">· {{ req.phone }}</span></div>
                                <p v-if="req.message" class="text-slate-600">{{ req.message }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
