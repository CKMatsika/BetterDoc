<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowRight, Building2, CheckCircle2, Lock, Shield, UserCheck, UserPlus, WifiOff } from '@lucide/vue';
import ClassificationBadge from '@/Components/ClassificationBadge.vue';
import type { SharedProps } from '@/types/betterdoc';

interface DemoUser {
    id: string;
    name: string;
    email: string;
    service_number: string | null;
    rank_title: string | null;
    tenant_slug: string | null;
    tenant_name: string | null;
    profile: string | null;
    clearance_name: string | null;
    clearance_color: string | null;
    clearance_rank: number;
    org_unit_code: string | null;
    compartments: string[];
    roles: string[];
}

const props = defineProps<{
    demoUsers: DemoUser[];
}>();

const page = usePage<{ props: SharedProps }>();
const shared = computed(() => page.props as unknown as SharedProps);
const branding = computed(() => shared.value.branding);

const form = useForm({
    identifier: '',
    password: '',
});

const groupedDemo = computed(() => {
    const groups: Record<string, DemoUser[]> = {};
    for (const u of props.demoUsers) {
        const key = u.tenant_name ?? 'Organisation';
        groups[key] ??= [];
        groups[key].push(u);
    }
    return groups;
});

function submit(): void {
    form.post('/login');
}

function demoSwitch(userId: string): void {
    router.post('/demo-login', { user_id: userId });
}
</script>

<template>
    <Head title="Sign In" />
    <div class="flex min-h-screen flex-col justify-between bg-slate-950 text-slate-100">
        <div class="flex items-center justify-between bg-slate-900 px-6 py-2 text-xs font-semibold tracking-wider uppercase border-b border-slate-800">
            <span class="flex items-center gap-2 text-white">
                <Shield class="h-4 w-4" :style="{ color: branding?.accent_color ?? '#F59E0B' }" />
                {{ branding?.name ?? 'BetterDoc DMS' }} // PORTAL SIGN IN
            </span>
            <span class="flex items-center gap-1.5 font-mono text-emerald-400">
                <WifiOff class="h-3.5 w-3.5" />
                {{ shared.app.air_gapped ? 'AIR-GAPPED MODE ACTIVE' : 'LAN MODE' }}
            </span>
        </div>

        <div class="mx-auto grid w-full max-w-6xl grid-cols-1 gap-8 px-4 py-10 lg:grid-cols-12 items-start">
            <!-- Standard Credentials Card -->
            <div
                :class="
                    shared.app.demo_switcher_enabled && demoUsers.length > 0
                        ? 'lg:col-span-5'
                        : shared.app.self_registration_enabled
                          ? 'lg:col-span-6'
                          : 'lg:col-span-6 lg:col-start-4'
                "
            >
                <div class="rounded-[14px] border border-slate-800 bg-slate-900 p-6 sm:p-8 shadow-xl">
                    <div class="mb-6 flex items-center gap-3">
                        <img
                            v-if="branding?.logo_dark_url || branding?.logo_url"
                            :src="branding.logo_dark_url || branding.logo_url || ''"
                            :alt="branding.name"
                            class="h-10 w-auto max-w-28 object-contain"
                        />
                        <img v-else src="/betterdoc-icon.svg" :alt="branding?.name ?? 'BetterDoc DMS'" class="h-10 w-10" />
                        <div>
                            <h1 class="text-lg font-bold text-white">Sign in to {{ branding?.short_name ?? 'BetterDoc DMS' }}</h1>
                            <p class="text-xs text-slate-400">{{ branding?.tagline ?? 'Local & LDAPS Directory Authentication + Mandatory MFA' }}</p>
                        </div>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <label for="login-identifier" class="mb-1 block text-xs font-medium text-slate-300">Email or Service Number</label>
                            <input
                                id="login-identifier"
                                v-model="form.identifier"
                                type="text"
                                required
                                autocomplete="username"
                                placeholder="username@organisation.lan or admin@company.com"
                                class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3.5 py-2.5 text-sm text-white placeholder-slate-500 focus:border-white focus:outline-2 focus:outline-white/40"
                            />
                            <p v-if="form.errors.identifier" class="mt-1 text-xs text-red-400">{{ form.errors.identifier }}</p>
                        </div>

                        <div>
                            <label for="login-password" class="mb-1 block text-xs font-medium text-slate-300">Password</label>
                            <input
                                id="login-password"
                                v-model="form.password"
                                type="password"
                                required
                                autocomplete="current-password"
                                class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3.5 py-2.5 text-sm text-white focus:border-white focus:outline-2 focus:outline-white/40"
                            />
                            <p v-if="form.errors.password" class="mt-1 text-xs text-red-400">{{ form.errors.password }}</p>
                        </div>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="flex w-full items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-semibold transition focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white disabled:opacity-50"
                            :style="{
                                backgroundColor: branding?.primary_color ?? '#0E7C66',
                                color: branding?.primary_fg ?? '#FFFFFF',
                            }"
                        >
                            <Lock class="h-4 w-4" />
                            <span>Sign in securely</span>
                        </button>
                    </form>

                    <div
                        v-if="shared.app.self_registration_enabled"
                        class="mt-6 flex flex-wrap items-center justify-between gap-2 border-t border-slate-800 pt-4 text-xs"
                    >
                        <span class="text-slate-400">New organisation?</span>
                        <Link href="/register" class="inline-flex items-center gap-1 font-semibold text-emerald-400 hover:text-emerald-300">
                            <UserPlus class="h-3.5 w-3.5" />
                            <span>Create Organisation (Sign up)</span>
                        </Link>
                    </div>

                    <div v-if="branding?.support_email || branding?.support_phone" class="mt-4 border-t border-slate-800 pt-3 text-xs text-slate-400">
                        Need access help? {{ branding.support_email }}
                        <span v-if="branding.support_phone"> · {{ branding.support_phone }}</span>
                    </div>
                </div>
            </div>

            <!-- Self-Service Organisation Onboarding Card (Default when Demo Switcher is off) -->
            <div
                v-if="!(shared.app.demo_switcher_enabled && demoUsers.length > 0) && shared.app.self_registration_enabled"
                class="lg:col-span-6"
            >
                <div class="rounded-[14px] border border-slate-800 bg-slate-900/75 p-6 sm:p-8 space-y-6">
                    <div>
                        <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-950/90 px-3 py-1 text-xs font-semibold text-emerald-300 ring-1 ring-emerald-700/50">
                            <Building2 class="h-3.5 w-3.5" />
                            Self-Service Organisation Onboarding
                        </span>
                        <h2 class="mt-3 text-xl font-bold text-white">
                            Create a Dedicated Organisation Portal
                        </h2>
                        <p class="mt-2 text-sm leading-relaxed text-slate-400">
                            Sign up as your organisation&apos;s Super Admin and launch an empty, isolated BetterDoc workspace tailored to your sector.
                        </p>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <span class="rounded-md border border-slate-700 bg-slate-950 px-2.5 py-1 text-xs font-semibold text-slate-200">Corporate</span>
                        <span class="rounded-md border border-slate-700 bg-slate-950 px-2.5 py-1 text-xs font-semibold text-slate-200">Government</span>
                        <span class="rounded-md border border-slate-700 bg-slate-950 px-2.5 py-1 text-xs font-semibold text-slate-200">Financial</span>
                        <span class="rounded-md border border-slate-700 bg-slate-950 px-2.5 py-1 text-xs font-semibold text-slate-200">Defence</span>
                    </div>

                    <ol class="space-y-3 text-xs text-slate-300">
                        <li class="flex items-start gap-2.5">
                            <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" />
                            <span>
                                <strong class="text-white">Empty Starter Vault:</strong> Provisioned with zero pre-loaded documents or dummy accounts — just your HQ unit &amp; security ladder.
                            </span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" />
                            <span>
                                <strong class="text-white">Configure Branding:</strong> Set company name, logo, colours, support desk contacts, and portal mode in Settings &gt; Branding.
                            </span>
                        </li>
                        <li class="flex items-start gap-2.5">
                            <CheckCircle2 class="mt-0.5 h-4 w-4 shrink-0 text-emerald-400" />
                            <span>
                                <strong class="text-white">Create Users &amp; Roles:</strong> Build departments, invite staff, and assign roles &amp; granular permissions under Super Admin.
                            </span>
                        </li>
                    </ol>

                    <Link
                        href="/register"
                        class="inline-flex w-full items-center justify-center gap-2 rounded-lg border border-emerald-500/40 bg-emerald-950/60 px-5 py-3 text-sm font-semibold text-emerald-200 transition hover:bg-emerald-900/70 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white"
                    >
                        <UserPlus class="h-4 w-4" />
                        <span>Create Organisation Workspace</span>
                        <ArrowRight class="h-4 w-4" />
                    </Link>
                </div>
            </div>

            <!-- Demo Switcher (Only visible when BETTERDOC_DEMO_SWITCHER=true) -->
            <div v-if="shared.app.demo_switcher_enabled && demoUsers.length > 0" class="lg:col-span-7">
                <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-bold text-white uppercase tracking-wider">Instant Demo Persona Switcher</h2>
                            <p class="text-xs text-slate-400">Switch portals immediately between Air Force (Defence), Golden Knot (Financial) &amp; ZimCorp (Corporate).</p>
                        </div>
                    </div>

                    <div class="space-y-5">
                        <div v-for="(users, orgName) in groupedDemo" :key="orgName">
                            <div class="mb-2 flex items-center gap-2">
                                <h3 class="text-xs font-semibold uppercase tracking-wider text-sky-400">{{ orgName }}</h3>
                                <span
                                    v-if="users[0]?.profile"
                                    class="rounded bg-slate-800 px-1.5 py-0.5 font-mono text-[10px] font-semibold uppercase text-slate-300"
                                >
                                    {{ users[0].profile }}
                                </span>
                            </div>
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <button
                                    v-for="u in users"
                                    :key="u.id"
                                    type="button"
                                    @click="demoSwitch(u.id)"
                                    class="flex flex-col items-start gap-1.5 rounded-lg border border-slate-800 bg-slate-950/80 p-3 text-left transition hover:border-sky-500 hover:bg-slate-900"
                                >
                                    <div class="flex w-full items-center justify-between gap-2">
                                        <span class="truncate text-xs font-semibold text-white">
                                            {{ u.rank_title ? `${u.rank_title} ` : '' }}{{ u.name }}
                                        </span>
                                        <ClassificationBadge
                                            :name="u.clearance_name ?? 'Level'"
                                            :color-hex="u.clearance_color ?? '#475569'"
                                            :rank="u.clearance_rank"
                                        />
                                    </div>
                                    <div class="flex w-full items-center justify-between text-[11px] text-slate-400">
                                        <span>{{ u.org_unit_code }} · {{ u.roles.join(', ') }}</span>
                                        <span class="inline-flex items-center gap-1 text-sky-400">
                                            <UserCheck class="h-3 w-3" /> Switch
                                        </span>
                                    </div>
                                    <div v-if="u.compartments.length > 0" class="flex flex-wrap gap-1">
                                        <span
                                            v-for="c in u.compartments"
                                            :key="c"
                                            class="rounded bg-slate-800 px-1.5 py-0.5 font-mono text-[10px] text-slate-300"
                                        >
                                            {{ c }}
                                        </span>
                                    </div>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="border-t border-slate-800 bg-slate-900 px-6 py-2 text-center text-[11px] text-slate-500">
            {{ branding?.name ?? 'BetterDoc DMS' }} · Offline MFA &amp; PostgreSQL RLS Enforced
            <span v-if="branding?.show_powered_by"> · Powered by BetterDoc DMS</span>
        </div>
    </div>
</template>
