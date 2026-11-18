<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import {
    Archive,
    Award,
    Bell,
    BookMarked,
    Bot,
    FilePlus2,
    FileText,
    GitFork,
    Inbox,
    LayoutDashboard,
    LogOut,
    Printer,
    ShieldCheck,
    SlidersHorizontal,
    UserCheck,
    KeyRound,
    CheckCircle2,
    AlertTriangle,
} from '@lucide/vue';
import ClassificationBanner from '@/Components/ClassificationBanner.vue';
import ClassificationBadge from '@/Components/ClassificationBadge.vue';
import { SentinelReverbClient } from '@/lib/reverbClient';
import type { SharedProps } from '@/types/sentinel';

const page = usePage<{ props: SharedProps }>();
const props = computed(() => page.props as unknown as SharedProps);
const user = computed(() => props.value.auth?.user ?? null);
const tenant = computed(() => props.value.tenant ?? null);

const showNotifMenu = ref(false);
let reverbClient: SentinelReverbClient | null = null;

const bannerColor = computed(() => user.value?.clearance?.color_hex ?? '#0F172A');
const bannerLabel = computed(() => user.value?.clearance?.name ?? 'UNAUTHENTICATED');

function logout(): void {
    router.post('/logout');
}

function markNotificationRead(id: string, actionUrl: string | null): void {
    router.post(
        `/notifications/${id}/read`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                showNotifMenu.value = false;
                if (actionUrl) {
                    router.visit(actionUrl);
                }
            },
        }
    );
}

onMounted(() => {
    const revCfg = props.value.app?.reverb;
    if (revCfg?.enabled && user.value && tenant.value) {
        reverbClient = new SentinelReverbClient(revCfg);
        reverbClient.connect();
        reverbClient.subscribe(`private-tenant.${tenant.value.id}.user.${user.value.id}`, () => {
            router.reload({ only: ['unreadNotificationsCount', 'unreadNotifications', 'pendingWorkflowTasksCount', 'pendingApprovalsCount'] });
        });
    }
});

onUnmounted(() => {
    reverbClient?.disconnect();
});

const navItems = computed(() => [
    { label: 'Dashboard', href: '/dashboard', icon: LayoutDashboard, badge: 0 },
    { label: tenant.value?.terminology?.document ? `${tenant.value.terminology.document} Vault` : 'Document Vault', href: '/documents', icon: FileText, badge: 0 },
    { label: tenant.value?.terminology?.registry_item ? 'Correspondence Registry' : 'Registry', href: '/registry', icon: BookMarked, badge: 0 },
    { label: 'Workflow Inbox', href: '/workflows/inbox', icon: Inbox, badge: props.value.pendingWorkflowTasksCount ?? 0 },
    { label: 'Workflow Designer', href: '/workflows', icon: GitFork, badge: 0 },
    { label: 'Templates & Merge', href: '/templates', icon: FilePlus2, badge: 0 },
    { label: 'AI Assistant & IDP', href: '/ai', icon: Bot, badge: 0 },
    { label: 'Print, Scan & Agent', href: '/print-scan', icon: Printer, badge: 0 },
    { label: 'Governance & WORM', href: '/governance', icon: Archive, badge: 0 },
    { label: 'Approvals & Break-Glass', href: '/approvals', icon: UserCheck, badge: props.value.pendingApprovalsCount ?? 0 },
    { label: 'PKI & Certificates', href: '/pki/certificates', icon: Award, badge: 0 },
    { label: 'Audit Ledger', href: '/audit', icon: ShieldCheck, badge: 0 },
    { label: 'Profiles & Security', href: '/admin/profiles', icon: SlidersHorizontal, badge: 0 },
    { label: 'MFA & Devices', href: '/security/mfa/setup', icon: KeyRound, badge: 0 },
]);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-slate-50 text-slate-900">
        <ClassificationBanner
            :label="bannerLabel"
            :color-hex="bannerColor"
            :air-gapped="tenant?.air_gapped ?? props.app.air_gapped"
            :hosting-label="tenant?.hosting_mode_label"
            :prefix="tenant?.branding?.banner_text_prefix"
        />

        <div class="flex flex-1">
            <!-- Left Navigation Sidebar -->
            <aside class="hidden w-64 shrink-0 flex-col border-r border-slate-200 bg-slate-900 text-slate-100 md:flex">
                <div class="border-b border-slate-800 px-5 py-4">
                    <div class="flex items-center gap-2.5">
                        <img src="/sentinel-icon.svg" alt="SENTINEL" class="h-8 w-8 rounded-md" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-bold tracking-tight text-white">
                                {{ tenant?.branding?.short_name ?? 'SENTINEL DMS' }}
                            </p>
                            <p class="truncate text-[11px] text-slate-400">
                                {{ tenant?.name ?? 'LAN-First Document Vault' }}
                            </p>
                        </div>
                    </div>
                </div>

                <nav class="flex-1 space-y-1 p-3">
                    <Link
                        v-for="item in navItems"
                        :key="item.href"
                        :href="item.href"
                        class="flex items-center justify-between rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                        :class="page.url.startsWith(item.href)
                            ? 'bg-sky-600 text-white'
                            : 'text-slate-300 hover:bg-slate-800 hover:text-white'"
                    >
                        <span class="flex items-center gap-2.5">
                            <component :is="item.icon" class="h-4 w-4 shrink-0" />
                            <span>{{ item.label }}</span>
                        </span>
                        <span
                            v-if="item.badge > 0"
                            class="rounded-full bg-amber-500 px-2 py-0.5 text-[11px] font-bold text-slate-950"
                        >
                            {{ item.badge }}
                        </span>
                    </Link>
                </nav>

                <!-- Active User Security Context Card -->
                <div v-if="user" class="border-t border-slate-800 bg-slate-950/60 p-4 text-xs">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <ClassificationBadge
                            v-if="user.clearance"
                            :name="user.clearance.name"
                            :color-hex="user.clearance.color_hex"
                            :rank="user.clearance_rank"
                        />
                        <span class="font-mono text-[10px] text-slate-400">{{ user.service_number ?? 'LOCAL' }}</span>
                    </div>
                    <p class="truncate font-semibold text-white">
                        {{ user.rank_title ? `${user.rank_title} ` : '' }}{{ user.name }}
                    </p>
                    <p class="truncate text-slate-400">{{ user.org_unit?.name }} ({{ user.roles.join(', ') }})</p>
                    <div v-if="user.compartments.length > 0" class="mt-2 flex flex-wrap gap-1">
                        <span
                            v-for="comp in user.compartments"
                            :key="comp.id"
                            class="rounded bg-slate-800 px-1.5 py-0.5 font-mono text-[10px] text-sky-300 border border-slate-700"
                        >
                            {{ comp.code }}
                        </span>
                    </div>
                    <button
                        type="button"
                        @click="logout"
                        class="mt-3 flex w-full items-center justify-center gap-1.5 rounded-md border border-slate-700 bg-slate-800 px-3 py-1.5 text-xs font-medium text-slate-200 hover:bg-slate-700"
                    >
                        <LogOut class="h-3.5 w-3.5" />
                        <span>Sign Out</span>
                    </button>
                </div>
            </aside>

            <!-- Main Content -->
            <main class="flex-1 overflow-x-hidden p-4 sm:p-6 lg:p-8">
                <!-- Live Offline Notifications Bar (Reverb) -->
                <div v-if="user" class="relative mb-4 flex items-center justify-end gap-3">
                    <button
                        type="button"
                        @click="showNotifMenu = !showNotifMenu"
                        class="relative inline-flex items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-medium text-slate-700 shadow-2xs hover:bg-slate-50"
                    >
                        <Bell class="h-3.5 w-3.5 text-slate-600" />
                        <span>Notifications</span>
                        <span
                            v-if="(props.unreadNotificationsCount ?? 0) > 0"
                            class="rounded-full bg-red-600 px-1.5 py-0.5 font-mono text-[10px] font-bold text-white"
                        >
                            {{ props.unreadNotificationsCount }}
                        </span>
                    </button>

                    <div
                        v-if="showNotifMenu"
                        class="absolute top-10 right-0 z-30 w-80 rounded-xl border border-slate-200 bg-white p-3 text-xs shadow-xl"
                    >
                        <div class="mb-2 flex items-center justify-between border-b border-slate-100 pb-2">
                            <span class="font-bold uppercase tracking-wider text-slate-600">Clearance Inbox</span>
                            <button
                                v-if="(props.unreadNotificationsCount ?? 0) > 0"
                                type="button"
                                @click="router.post('/notifications/read-all', {}, { preserveScroll: true })"
                                class="text-[11px] font-medium text-sky-700 hover:underline"
                            >
                                Mark all read
                            </button>
                        </div>
                        <div v-if="(props.unreadNotifications ?? []).length === 0" class="py-3 text-center text-slate-500">
                            No recent notifications.
                        </div>
                        <div v-else class="max-h-72 space-y-1.5 overflow-y-auto">
                            <button
                                v-for="n in props.unreadNotifications"
                                :key="n.id"
                                type="button"
                                @click="markNotificationRead(n.id, n.action_url)"
                                class="block w-full rounded-lg border p-2 text-left transition hover:bg-slate-50"
                                :class="n.read_at ? 'border-slate-100 text-slate-500' : 'border-sky-200 bg-sky-50/40 text-slate-900'"
                            >
                                <div class="font-semibold">{{ n.title }}</div>
                                <p class="mt-0.5 text-[11px] text-slate-600">{{ n.body }}</p>
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="props.flash.success" class="mb-4 flex items-center gap-2 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">
                    <CheckCircle2 class="h-4 w-4 shrink-0 text-emerald-600" />
                    <span>{{ props.flash.success }}</span>
                </div>
                <div v-if="props.flash.error" class="mb-4 flex items-center gap-2 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">
                    <AlertTriangle class="h-4 w-4 shrink-0 text-red-600" />
                    <span>{{ props.flash.error }}</span>
                </div>

                <slot />
            </main>
        </div>

        <ClassificationBanner
            :label="bannerLabel"
            :color-hex="bannerColor"
            :air-gapped="tenant?.air_gapped ?? props.app.air_gapped"
            :hosting-label="tenant?.hosting_mode_label"
            :prefix="tenant?.branding?.banner_text_prefix"
        />
    </div>
</template>
