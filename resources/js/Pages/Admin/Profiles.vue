<script setup lang="ts">
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { SlidersHorizontal } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClassificationBadge from '@/Components/ClassificationBadge.vue';
import type { SharedProps } from '@/types/sentinel';

defineProps<{
    availablePacks: {
        code: string;
        name: string;
        hosting_mode_default: string;
        air_gapped_enforced: boolean;
        write_down_mode: string;
    }[];
    activeConfig: Record<string, unknown>;
    clearanceLevels: {
        id: string;
        rank: number;
        code: string;
        name: string;
        color_hex: string;
        watermark_mode: string;
        two_person_required: boolean;
        secure_print_required: boolean;
        cloud_sync_allowed: boolean;
    }[];
    compartments: { id: string; code: string; name: string; description: string | null }[];
    canSwitchProfile: boolean;
}>();

const page = usePage<{ props: SharedProps }>();
const shared = computed(() => page.props as unknown as SharedProps);

function applyProfile(code: string): void {
    router.post('/admin/profiles/apply', { profile_code: code });
}
</script>

<template>
    <Head title="Organisation Profiles & Security" />
    <AppLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-xl font-bold text-slate-900">Organisation Configuration Profiles</h1>
                <p class="text-xs text-slate-600">One codebase configured declaratively for Defence, Financial, or Corporate deployments.</p>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div
                    v-for="pack in availablePacks"
                    :key="pack.code"
                    class="rounded-xl border p-5 shadow-xs"
                    :class="shared.tenant?.active_profile_code === pack.code ? 'border-sky-600 bg-sky-50' : 'border-slate-200 bg-white'"
                >
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-xs font-bold uppercase text-sky-700">{{ pack.code }}</span>
                        <SlidersHorizontal class="h-4 w-4 text-slate-400" />
                    </div>
                    <h2 class="mt-2 text-base font-bold text-slate-900">{{ pack.name }}</h2>
                    <p class="mt-1 text-xs text-slate-600">Write-Down: <span class="font-mono font-semibold">{{ pack.write_down_mode }}</span></p>
                    <p class="text-xs text-slate-600">Air-Gap Enforced: <span class="font-mono font-semibold">{{ pack.air_gapped_enforced ? 'YES' : 'OPTIONAL' }}</span></p>
                    <button
                        v-if="canSwitchProfile && shared.tenant?.active_profile_code !== pack.code"
                        type="button"
                        @click="applyProfile(pack.code)"
                        class="mt-4 w-full rounded-lg bg-slate-900 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800"
                    >
                        Apply Profile Pack
                    </button>
                </div>
            </div>

            <!-- Clearance Ladder -->
            <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
                <h2 class="mb-3 text-sm font-bold uppercase tracking-wider text-slate-700">Active Clearance Ladder</h2>
                <table class="min-w-full divide-y divide-slate-200 text-xs">
                    <thead class="bg-slate-50 text-left font-semibold uppercase text-slate-500">
                        <tr>
                            <th class="px-4 py-2">Level</th>
                            <th class="px-4 py-2">Watermark</th>
                            <th class="px-4 py-2">Two-Person Rule</th>
                            <th class="px-4 py-2">Secure Print</th>
                            <th class="px-4 py-2">Cloud Sync</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="lvl in clearanceLevels" :key="lvl.id">
                            <td class="px-4 py-2.5">
                                <ClassificationBadge :name="lvl.name" :color-hex="lvl.color_hex" :rank="lvl.rank" />
                            </td>
                            <td class="px-4 py-2.5 font-mono">{{ lvl.watermark_mode }}</td>
                            <td class="px-4 py-2.5">{{ lvl.two_person_required ? 'Mandatory' : 'No' }}</td>
                            <td class="px-4 py-2.5">{{ lvl.secure_print_required ? 'Held PIN/Badge' : 'Direct' }}</td>
                            <td class="px-4 py-2.5">{{ lvl.cloud_sync_allowed ? 'Allowed' : 'Forbidden' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
