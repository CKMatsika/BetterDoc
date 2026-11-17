<script setup lang="ts">
import { onMounted, ref } from 'vue';

const emit = defineEmits<{
    'update:modelValue': [svgPath: string];
}>();

defineProps<{
    modelValue: string;
}>();

const canvasRef = ref<HTMLCanvasElement | null>(null);
const isDrawing = ref(false);
const pathCommands = ref<string[]>([]);

onMounted(() => {
    resetCanvas();
});

function resetCanvas(): void {
    const canvas = canvasRef.value;
    if (!canvas) {
        return;
    }
    const ctx = canvas.getContext('2d');
    if (!ctx) {
        return;
    }
    ctx.fillStyle = '#FFFFFF';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.strokeStyle = '#0F172A';
    ctx.lineWidth = 2;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
}

function pointFromEvent(e: PointerEvent): { x: number; y: number } {
    const canvas = canvasRef.value;
    if (!canvas) {
        return { x: 0, y: 0 };
    }
    const rect = canvas.getBoundingClientRect();
    const scaleX = canvas.width / rect.width;
    const scaleY = canvas.height / rect.height;
    return {
        x: Math.round((e.clientX - rect.left) * scaleX),
        y: Math.round((e.clientY - rect.top) * scaleY),
    };
}

function startDraw(e: PointerEvent): void {
    const canvas = canvasRef.value;
    const ctx = canvas?.getContext('2d');
    if (!canvas || !ctx) {
        return;
    }
    isDrawing.value = true;
    const pt = pointFromEvent(e);
    ctx.beginPath();
    ctx.moveTo(pt.x, pt.y);
    pathCommands.value.push(`M ${pt.x} ${pt.y}`);
    emit('update:modelValue', pathCommands.value.join(' '));
}

function drawMove(e: PointerEvent): void {
    if (!isDrawing.value) {
        return;
    }
    const canvas = canvasRef.value;
    const ctx = canvas?.getContext('2d');
    if (!canvas || !ctx) {
        return;
    }
    const pt = pointFromEvent(e);
    ctx.lineTo(pt.x, pt.y);
    ctx.stroke();
    pathCommands.value.push(`L ${pt.x} ${pt.y}`);
    emit('update:modelValue', pathCommands.value.join(' '));
}

function endDraw(): void {
    isDrawing.value = false;
}

function clearPad(): void {
    pathCommands.value = [];
    resetCanvas();
    emit('update:modelValue', '');
}
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-center justify-between text-xs text-slate-600">
            <span>Draw Signature Stroke</span>
            <button type="button" @click="clearPad" class="text-sky-700 hover:underline">Clear</button>
        </div>
        <canvas
            ref="canvasRef"
            width="240"
            height="80"
            @pointerdown="startDraw"
            @pointermove="drawMove"
            @pointerup="endDraw"
            @pointerleave="endDraw"
            class="w-full cursor-crosshair touch-none rounded border border-slate-300 bg-white"
        />
    </div>
</template>
