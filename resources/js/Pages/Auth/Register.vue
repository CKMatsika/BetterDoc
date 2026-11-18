<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Building2, CheckCircle2, Lock, Shield, ShieldCheck, Users, WifiOff } from '@lucide/vue';
import type { SharedProps } from '@/types/betterdoc';

interface OrganisationTypeOption {
    code: 'corporate' | 'government' | 'financial' | 'defence';
    name: string;
    summary: string;
}

const props = defineProps<{
    organisationTypes: OrganisationTypeOption[];
}>();

const page = usePage<{ props: SharedProps }>();
const shared = computed(() => page.props as unknown as SharedProps);
const branding = computed(() => shared.value.branding);

const form = useForm<{
    organisation_type: 'corporate' | 'government' | 'financial' | 'defence';
    organisation_name: string;
    name: string;
    email: string;
    password: string;
    password_confirmation: string;
}>({
    organisation_type: props.organisationTypes[0]?.code ?? 'corporate',
    organisation_name: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const selectedType = computed(
    () => props.organisationTypes.find((t) => t.code === form.organisation_type) ?? props.organisationTypes[0],
);

function submit(): void {
    form.post('/register');
}
</script>

<template>
    <Head title="Create Organisation — BetterDoc DMS" />

    <div class="flex min-h-screen flex-col justify-between bg-slate-950 text-slate-100">
        <!-- Top Status Bar -->
        <header class="border-b border-slate-800 bg-slate-900 px-6 py-3">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 text-xs">
                <div class="flex items-center gap-2.5 font-semibold tracking-wider text-white uppercase">
                    <Shield class="h-4 w-4 text-[#0E7C66]" />
                    <span>BetterDoc DMS // Create Organisation Workspace</span>
                </div>
                <div class="flex items-center gap-4">
                    <span class="hidden items-center gap-1.5 font-mono text-emerald-400 sm:inline-flex">
                        <WifiOff class="h-3.5 w-3.5" />
                        {{ shared.app.air_gapped ? 'AIR-GAPPED READY' : 'LAN MODE' }}
                    </span>
                    <Link
                        href="/login"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-800 px-3 py-1.5 font-semibold text-slate-200 transition hover:bg-slate-700"
                    >
                        <ArrowLeft class="h-3.5 w-3.5" />
                        Back to Sign in
                    </Link>
                </div>
            </div>
        </header>

        <main class="mx-auto w-full max-w-6xl flex-1 px-4 py-10">
            <div class="grid grid-cols-1 gap-8 lg:grid-cols-12">
                <!-- Left Column: Organisation Type & Provisioning Overview -->
                <section class="space-y-5 lg:col-span-5">
                    <div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-950/90 px-3 py-1 text-xs font-semibold text-emerald-300 ring-1 ring-emerald-700/50">
                            <Building2 class="h-3.5 w-3.5" />
                            Self-Service Organisation Provisioning
                        </span>
                        <h1 class="mt-3 text-2xl font-bold tracking-tight text-white">
                            Launch Your Private Organisation Portal
                        </h1>
                        <p class="mt-2 text-sm leading-relaxed text-slate-400">
                            Select your sector profile below. We provision a clean, empty workspace tailored to your security model so you can configure your branding and create users.
                        </p>
                    </div>

                    <div class="space-y-3">
                        <p class="text-xs font-semibold tracking-wider text-slate-400 uppercase">
                            1. Select Organisation Type *
                        </p>
                        <button
                            v-for="item in organisationTypes"
                            :key="item.code"
                            type="button"
                            @click="form.organisation_type = item.code"
                            class="w-full rounded-[14px] border p-4 text-left transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                            :class="
                                form.organisation_type === item.code
                                    ? 'border-[#0E7C66] bg-slate-900 ring-1 ring-[#0E7C66]'
                                    : 'border-slate-800 bg-slate-900/60 hover:border-slate-700 hover:bg-slate-900'
                            "
                        >
                            <div class="flex items-center justify-between gap-2">
                                <span class="text-sm font-bold text-white">{{ item.name }}</span>
                                <span
                                    class="rounded-md px-2 py-0.5 font-mono text-[10px] font-bold uppercase"
                                    :class="
                                        form.organisation_type === item.code
                                            ? 'bg-[#0E7C66] text-white'
                                            : 'bg-slate-800 text-slate-400'
                                    "
                                >
                                    {{ item.code }}
                                </span>
                            </div>
                            <p class="mt-1.5 text-xs leading-relaxed text-slate-400">{{ item.summary }}</p>
                        </button>
                        <p v-if="form.errors.organisation_type" class="text-xs text-red-400">
                            {{ form.errors.organisation_type }}
                        </p>
                    </div>

                    <div class="rounded-[14px] border border-slate-800 bg-slate-900/60 p-4 text-xs text-slate-300 space-y-2">
                        <p class="font-bold text-white flex items-center gap-1.5">
                            <ShieldCheck class="h-4 w-4 text-emerald-400" />
                            What gets created for you:
                        </p>
                        <ul class="space-y-1.5 text-slate-400">
                            <li class="flex items-center gap-2">
                                <CheckCircle2 class="h-3.5 w-3.5 shrink-0 text-emerald-400" />
                                Empty workspace (zero demo files or dummy accounts)
                            </li>
                            <li class="flex items-center gap-2">
                                <CheckCircle2 class="h-3.5 w-3.5 shrink-0 text-emerald-400" />
                                Headquarters unit &amp; empty General Workspace cabinet
                            </li>
                            <li class="flex items-center gap-2">
                                <CheckCircle2 class="h-3.5 w-3.5 shrink-0 text-emerald-400" />
                                {{ selectedType?.name }} security ladder &amp; full Role/Permission catalog
                            </li>
                            <li class="flex items-center gap-2">
                                <CheckCircle2 class="h-3.5 w-3.5 shrink-0 text-emerald-400" />
                                Immediate onboarding into Settings &gt; Branding, then Users &amp; Roles
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- Right Column: Super Admin Sign Up Form -->
                <section class="lg:col-span-7">
                    <div class="rounded-[14px] border border-slate-800 bg-slate-900 p-6 shadow-xl sm:p-8">
                        <div class="mb-6 border-b border-slate-800 pb-4">
                            <h2 class="text-lg font-bold text-white">2. Organisation &amp; Super Admin Account</h2>
                            <p class="mt-1 text-xs text-slate-400">
                                Your initial account receives the <span class="font-semibold text-emerald-400">SystemAdmin</span> role and all 15 granular platform permissions.
                            </p>
                        </div>

                        <form @submit.prevent="submit" class="space-y-4">
                            <div>
                                <label for="reg-org-name" class="mb-1 block text-xs font-medium text-slate-300">
                                    Organisation Name *
                                </label>
                                <input
                                    id="reg-org-name"
                                    v-model="form.organisation_name"
                                    type="text"
                                    required
                                    placeholder="e.g. Acme Holdings or Ministry of Finance"
                                    class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 focus:border-white focus:outline-2 focus:outline-white/40"
                                />
                                <p v-if="form.errors.organisation_name" class="mt-1 text-xs text-red-400">
                                    {{ form.errors.organisation_name }}
                                </p>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="reg-name" class="mb-1 block text-xs font-medium text-slate-300">
                                        Super Admin Full Name *
                                    </label>
                                    <input
                                        id="reg-name"
                                        v-model="form.name"
                                        type="text"
                                        required
                                        autocomplete="name"
                                        placeholder="e.g. Clement M."
                                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 focus:border-white focus:outline-2 focus:outline-white/40"
                                    />
                                    <p v-if="form.errors.name" class="mt-1 text-xs text-red-400">{{ form.errors.name }}</p>
                                </div>

                                <div>
                                    <label for="reg-email" class="mb-1 block text-xs font-medium text-slate-300">
                                        Super Admin Work Email *
                                    </label>
                                    <input
                                        id="reg-email"
                                        v-model="form.email"
                                        type="email"
                                        required
                                        autocomplete="email"
                                        placeholder="admin@company.com"
                                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 focus:border-white focus:outline-2 focus:outline-white/40"
                                    />
                                    <p v-if="form.errors.email" class="mt-1 text-xs text-red-400">{{ form.errors.email }}</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="reg-password" class="mb-1 block text-xs font-medium text-slate-300">
                                        Password * (min 8 characters)
                                    </label>
                                    <input
                                        id="reg-password"
                                        v-model="form.password"
                                        type="password"
                                        required
                                        minlength="8"
                                        autocomplete="new-password"
                                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 focus:border-white focus:outline-2 focus:outline-white/40"
                                    />
                                    <p v-if="form.errors.password" class="mt-1 text-xs text-red-400">{{ form.errors.password }}</p>
                                </div>

                                <div>
                                    <label for="reg-password-confirm" class="mb-1 block text-xs font-medium text-slate-300">
                                        Confirm Password *
                                    </label>
                                    <input
                                        id="reg-password-confirm"
                                        v-model="form.password_confirmation"
                                        type="password"
                                        required
                                        minlength="8"
                                        autocomplete="new-password"
                                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 focus:border-white focus:outline-2 focus:outline-white/40"
                                    />
                                </div>
                            </div>

                            <div class="rounded-lg border border-slate-800 bg-slate-950/70 p-3.5 text-xs text-slate-400 flex items-start gap-2.5">
                                <Users class="mt-0.5 h-4 w-4 shrink-0 text-sky-400" />
                                <span>
                                    After signup, you will land in <strong class="text-slate-200">Settings &gt; Organisation Branding</strong> to set your company name, contact details, logos, and welcome portal, then create staff accounts under <strong class="text-slate-200">Users &amp; Roles</strong>.
                                </span>
                            </div>

                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="flex w-full items-center justify-center gap-2 rounded-lg px-5 py-3 text-sm font-semibold text-white transition hover:opacity-95 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:opacity-50"
                                :style="{
                                    backgroundColor: branding?.primary_color ?? '#0E7C66',
                                    color: branding?.primary_fg ?? '#FFFFFF',
                                }"
                            >
                                <Lock class="h-4 w-4" />
                                <span>Create Empty Organisation &amp; Sign In</span>
                            </button>
                        </form>

                        <div class="mt-6 border-t border-slate-800 pt-4 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-400">
                            <span>Already created an organisation?</span>
                            <Link href="/login" class="font-semibold text-emerald-400 hover:text-emerald-300">
                                Sign in to your account &rarr;
                            </Link>
                        </div>
                    </div>
                </section>
            </div>
        </main>

        <footer class="border-t border-slate-800 bg-slate-900 px-6 py-3 text-center text-[11px] text-slate-500">
            BetterDoc DMS · Offline-First Multi-Tenant PostgreSQL RLS Enforced
        </footer>
    </div>
</template>
