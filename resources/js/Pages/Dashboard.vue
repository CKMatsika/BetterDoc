<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { FileText, FolderLock, ShieldAlert, UserCheck, Activity } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClassificationBadge from '@/Components/ClassificationBadge.vue';
import type { SharedProps } from '@/types/sentinel';

interface RecentDoc {
    id: string;
    doc_number: string;
    title: string;
    status: string;
    classification_rank: number;
    classification: { name: string; color_hex: string };
    org_unit_code: string | null;
    compartments: string[];
}

interface OrgNode {
    id: string;
    code: string;
    name: string;
    unit_type: string;
    path: string;
    is_current: boolean;
}

defineProps<{
    stats: {
        accessible_documents: number;
        accessible_cabinets: number;
        pending_approvals: number;
        active_break_glass: number;
        audit_events_count: number;
    };
    recentDocuments: RecentDoc[];
    orgTree: OrgNode[];
}>();

const page = usePage<{ props: SharedProps }>();
const shared = computed(() => page.props as unknown as SharedProps);
</script>

<template>
    <Head title="Command Dashboard" />
    <AppLayout>
        <div class="space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-slate-900">
                        {{ shared.tenant?.branding?.organization_name ?? 'SENTINEL Workspace' }}
                    </h1>
                    <p class="text-sm text-slate-600">
                        Active Profile: <span class="font-semibold uppercase">{{ shared.tenant?.active_profile_code }}</span>
                        · PostgreSQL Row-Level Security &amp; Bell-LaPadula MAC Enforced
                    </p>
                </div>
                <Link
                    href="/documents"
                    class="inline-flex items-center gap-2 rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                >
                    <FileText class="h-4 w-4" />
                    <span>Open {{ shared.tenant?.terminology?.document ?? 'Document' }} Vault</span>
                </Link>
            </div>

            <!-- Metrics Grid -->
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                    <div class="flex items-center justify-between text-xs font-medium text-slate-500">
                        <span>Cleared {{ shared.tenant?.terminology?.document ?? 'Documents' }}</span>
                        <FileText class="h-4 w-4 text-sky-600" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900">{{ stats.accessible_documents }}</p>
                    <p class="mt-1 text-xs text-slate-500">Across {{ stats.accessible_cabinets }} cleared cabinets</p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                    <div class="flex items-center justify-between text-xs font-medium text-slate-500">
                        <span>Two-Person Queue</span>
                        <UserCheck class="h-4 w-4 text-amber-600" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900">{{ stats.pending_approvals }}</p>
                    <p class="mt-1 text-xs text-slate-500">Pending dual-control actions</p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                    <div class="flex items-center justify-between text-xs font-medium text-slate-500">
                        <span>Active Break-Glass</span>
                        <ShieldAlert class="h-4 w-4 text-red-600" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900">{{ stats.active_break_glass }}</p>
                    <p class="mt-1 text-xs text-slate-500">Emergency time-boxed overrides</p>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                    <div class="flex items-center justify-between text-xs font-medium text-slate-500">
                        <span>Audit Ledger Events</span>
                        <Activity class="h-4 w-4 text-emerald-600" />
                    </div>
                    <p class="mt-2 text-2xl font-bold text-slate-900">{{ stats.audit_events_count }}</p>
                    <p class="mt-1 text-xs text-slate-500">SHA-256 hash-chained &amp; append-only</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Recent Cleared Documents -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs lg:col-span-2">
                    <div class="mb-4 flex items-center justify-between">
                        <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider">Recent Cleared Records</h2>
                        <Link href="/documents" class="text-xs font-semibold text-sky-600 hover:underline">View All</Link>
                    </div>
                    <div v-if="recentDocuments.length === 0" class="rounded-lg border border-dashed border-slate-200 p-6 text-center text-sm text-slate-500">
                        No documents match your current clearance level and compartments.
                    </div>
                    <div v-else class="divide-y divide-slate-100">
                        <Link
                            v-for="doc in recentDocuments"
                            :key="doc.id"
                            :href="`/documents/${doc.id}`"
                            class="flex items-center justify-between gap-4 py-3 hover:bg-slate-50 px-2 rounded-lg transition-colors"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-slate-900">{{ doc.title }}</p>
                                <p class="font-mono text-xs text-slate-500">{{ doc.doc_number }} · {{ doc.org_unit_code }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <span
                                    v-for="c in doc.compartments"
                                    :key="c"
                                    class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] font-medium text-slate-700"
                                >
                                    {{ c }}
                                </span>
                                <ClassificationBadge
                                    :name="doc.classification.name"
                                    :color-hex="doc.classification.color_hex"
                                    :rank="doc.classification_rank"
                                />
                            </div>
                        </Link>
                    </div>
                </div>

                <!-- Org Unit Hierarchy -->
                <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                    <div class="mb-4 flex items-center gap-2">
                        <FolderLock class="h-4 w-4 text-slate-700" />
                        <h2 class="text-sm font-bold text-slate-900 uppercase tracking-wider">
                            {{ shared.tenant?.terminology?.unit ?? 'Org Hierarchy' }}
                        </h2>
                    </div>
                    <div class="space-y-2">
                        <div
                            v-for="node in orgTree"
                            :key="node.id"
                            class="rounded-lg border px-3 py-2 text-xs"
                            :class="node.is_current ? 'border-sky-500 bg-sky-50 text-sky-950' : 'border-slate-200 bg-slate-50 text-slate-700'"
                            :style="{ marginLeft: `${Math.max(0, (node.path.split('.').length - 1) * 12)}px` }"
                        >
                            <div class="flex items-center justify-between font-semibold">
                                <span>{{ node.name }}</span>
                                <span class="font-mono text-[10px]">{{ node.code }}</span>
                            </div>
                            <span class="text-[10px] uppercase text-slate-500">{{ node.unit_type }} · {{ node.path }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
