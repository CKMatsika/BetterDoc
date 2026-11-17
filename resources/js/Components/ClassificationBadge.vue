<script setup lang="ts">
import { ShieldAlert } from '@lucide/vue';

defineProps<{
    name: string;
    colorHex: string;
    rank?: number;
}>();
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-0.5 text-xs font-semibold text-white shadow-xs"
        :style="{ backgroundColor: colorHex || '#475569' }"
    >
        <ShieldAlert class="h-3 w-3 shrink-0" />
        <span>{{ name }}</span>
        <span v-if="rank !== undefined" class="font-mono text-[10px] opacity-85">L{{ rank }}</span>
    </span>
</template>
