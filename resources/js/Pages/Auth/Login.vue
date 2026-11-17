<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Lock, Shield, WifiOff, UserCheck } from '@lucide/vue';
import ClassificationBadge from '@/Components/ClassificationBadge.vue';
import type { SharedProps } from '@/types/sentinel';

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
            <span class="flex items-center gap-2 text-sky-400">
                <Shield class="h-4 w-4" />
                SENTINEL DMS // LAN-FIRST CLASSIFIED PORTAL
            </span>
            <span class="flex items-center gap-1.5 font-mono text-emerald-400">
                <WifiOff class="h-3.5 w-3.5" />
                {{ shared.app.air_gapped ? 'AIR-GAPPED MODE ACTIVE' : 'LAN MODE' }}
            </span>
        </div>

        <div class="mx-auto grid w-full max-w-6xl grid-cols-1 gap-8 px-4 py-10 lg:grid-cols-12">
            <!-- Standard Credentials Card -->
            <div class="lg:col-span-5">
                <div class="rounded-xl border border-slate-800 bg-slate-900 p-6 shadow-xl">
                    <div class="mb-6 flex items-center gap-3">
                        <img src="/sentinel-icon.svg" alt="SENTINEL" class="h-10 w-10" />
                        <div>
                            <h1 class="text-lg font-bold text-white">Sign in to SENTINEL</h1>
                            <p class="text-xs text-slate-400">Local &amp; LDAPS Directory Authentication + Mandatory MFA</p>
                        </div>
                    </div>

                    <form @submit.prevent="submit" class="space-y-4">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-300">Email or Service Number</label>
                            <input
                                v-model="form.identifier"
                                type="text"
                                required
                                autocomplete="username"
                                placeholder="wgcdr.moyo@afz.mil.zw or AFZ-0142"
                                class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white placeholder-slate-500 focus:border-sky-500 focus:outline-none"
                            />
                            <p v-if="form.errors.identifier" class="mt-1 text-xs text-red-400">{{ form.errors.identifier }}</p>
                        </div>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-300">Password</label>
                            <input
                                v-model="form.password"
                                type="password"
                                required
                                autocomplete="current-password"
                                class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-white focus:border-sky-500 focus:outline-none"
                            />
                            <p v-if="form.errors.password" class="mt-1 text-xs text-red-400">{{ form.errors.password }}</p>
                        </div>

                        <button
                            type="submit"
                            :disabled="form.processing"
                            class="flex w-full items-center justify-center gap-2 rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-sky-500 disabled:opacity-50"
                        >
                            <Lock class="h-4 w-4" />
                            <span>Authenticate &amp; Verify MFA</span>
                        </button>
                    </form>
                </div>
            </div>

            <!-- Demo Switcher (Only visible when SENTINEL_DEMO_SWITCHER=true) -->
            <div v-if="shared.app.demo_switcher_enabled && demoUsers.length > 0" class="lg:col-span-7">
                <div class="rounded-xl border border-slate-800 bg-slate-900/70 p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h2 class="text-sm font-bold text-white uppercase tracking-wider">Instant Demo Persona Switcher</h2>
                            <p class="text-xs text-slate-400">Test Air Force (Defence) &amp; Golden Knot (Financial) MAC clearances immediately.</p>
                        </div>
                    </div>

                    <div class="space-y-5">
                        <div v-for="(users, orgName) in groupedDemo" :key="orgName">
                            <h3 class="mb-2 text-xs font-semibold uppercase tracking-wider text-sky-400">{{ orgName }}</h3>
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
            SENTINEL DMS · Zero External CDN/Font Calls · Hardware/Offline TOTP + WebAuthn · PostgreSQL RLS Enforced
        </div>
    </div>
</template>
