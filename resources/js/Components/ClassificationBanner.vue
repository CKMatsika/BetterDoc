<script setup lang="ts">
import { Lock, WifiOff, Globe } from '@lucide/vue';

defineProps<{
    label: string;
    colorHex: string;
    airGapped: boolean;
    hostingLabel?: string;
    prefix?: string;
}>();
</script>

<template>
    <div
        class="flex items-center justify-between px-4 py-1 text-xs font-semibold tracking-wider text-white uppercase select-none"
        :style="{ backgroundColor: colorHex || '#0F172A' }"
    >
        <div class="flex items-center gap-2">
            <Lock class="h-3.5 w-3.5 shrink-0" />
            <span>{{ prefix ? `${prefix} // ` : '' }}MAX CLEARANCE: {{ label }}</span>
        </div>
        <div class="flex items-center gap-3 font-mono text-[11px]">
            <span v-if="hostingLabel" class="hidden sm:inline opacity-90">{{ hostingLabel }}</span>
            <span
                class="inline-flex items-center gap-1 rounded px-2 py-0.5"
                :class="airGapped ? 'bg-black/30 text-emerald-300' : 'bg-black/25 text-amber-200'"
            >
                <WifiOff v-if="airGapped" class="h-3 w-3" />
                <Globe v-else class="h-3 w-3" />
                {{ airGapped ? 'AIR-GAPPED LAN' : 'LAN HYBRID' }}
            </span>
        </div>
    </div>
</template>
