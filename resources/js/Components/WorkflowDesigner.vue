<script setup lang="ts">
import { computed, ref } from 'vue';
import { GitBranch, GitMerge, Lock, Play, Plus, Square, Trash2, UserCheck } from '@lucide/vue';

export interface BpmnNode {
    id: string;
    type: 'start' | 'approval' | 'parallel_fork' | 'parallel_join' | 'conditional_gateway' | 'archive_worm' | 'end';
    label: string;
    assignee_role?: string;
    assignee_user_id?: string;
    sla_hours?: number;
    x: number;
    y: number;
}

export interface BpmnEdge {
    id: string;
    source: string;
    target: string;
    condition: string;
    is_default: boolean;
}

const props = defineProps<{
    modelValue: { nodes: BpmnNode[]; edges: BpmnEdge[] };
    roles: string[];
    users: { id: string; name: string; rank_title: string | null; clearance_rank: number }[];
}>();

const emit = defineEmits<{
    'update:modelValue': [value: { nodes: BpmnNode[]; edges: BpmnEdge[] }];
}>();

const canvasRef = ref<HTMLDivElement | null>(null);
const selectedNodeId = ref<string | null>(props.modelValue.nodes[0]?.id ?? null);
const connectSourceId = ref<string | null>(null);
const draggingNodeId = ref<string | null>(null);
const dragOffset = ref({ x: 0, y: 0 });

const selectedNode = computed(() => props.modelValue.nodes.find((n) => n.id === selectedNodeId.value) ?? null);

function updateGraph(nodes: BpmnNode[], edges: BpmnEdge[]): void {
    emit('update:modelValue', { nodes, edges });
}

function addNode(type: BpmnNode['type']): void {
    const id = `n_${Date.now().toString(36).slice(-4)}_${props.modelValue.nodes.length + 1}`;
    const labelMap: Record<BpmnNode['type'], string> = {
        start: 'Start',
        approval: 'Approval Step',
        parallel_fork: 'Parallel Split',
        parallel_join: 'Parallel Join',
        conditional_gateway: 'Condition Gateway',
        archive_worm: 'Archive WORM Seal',
        end: 'End',
    };
    const nextNode: BpmnNode = {
        id,
        type,
        label: labelMap[type],
        assignee_role: type === 'approval' ? props.roles[0] ?? 'approver' : undefined,
        sla_hours: type === 'approval' ? 24 : undefined,
        x: 120 + ((props.modelValue.nodes.length * 45) % 420),
        y: 80 + ((props.modelValue.nodes.length * 35) % 180),
    };
    selectedNodeId.value = id;
    updateGraph([...props.modelValue.nodes, nextNode], props.modelValue.edges);
}

function removeNode(nodeId: string): void {
    const nodes = props.modelValue.nodes.filter((n) => n.id !== nodeId);
    const edges = props.modelValue.edges.filter((e) => e.source !== nodeId && e.target !== nodeId);
    if (selectedNodeId.value === nodeId) {
        selectedNodeId.value = nodes[0]?.id ?? null;
    }
    updateGraph(nodes, edges);
}

function startOrCompleteConnection(nodeId: string): void {
    if (!connectSourceId.value) {
        connectSourceId.value = nodeId;
        return;
    }
    if (connectSourceId.value === nodeId) {
        connectSourceId.value = null;
        return;
    }
    const exists = props.modelValue.edges.some((e) => e.source === connectSourceId.value && e.target === nodeId);
    if (!exists) {
        const edge: BpmnEdge = {
            id: `e_${Date.now().toString(36).slice(-4)}_${props.modelValue.edges.length + 1}`,
            source: connectSourceId.value,
            target: nodeId,
            condition: '',
            is_default: false,
        };
        updateGraph(props.modelValue.nodes, [...props.modelValue.edges, edge]);
    }
    connectSourceId.value = null;
}

function removeEdge(edgeId: string): void {
    updateGraph(
        props.modelValue.nodes,
        props.modelValue.edges.filter((e) => e.id !== edgeId)
    );
}

function startDrag(event: MouseEvent, node: BpmnNode): void {
    const rect = canvasRef.value?.getBoundingClientRect();
    if (!rect) {
        return;
    }
    selectedNodeId.value = node.id;
    draggingNodeId.value = node.id;
    dragOffset.value = {
        x: event.clientX - rect.left - node.x,
        y: event.clientY - rect.top - node.y,
    };
}

function onDragMove(event: MouseEvent): void {
    if (!draggingNodeId.value || !canvasRef.value) {
        return;
    }
    const rect = canvasRef.value.getBoundingClientRect();
    const nx = Math.max(10, Math.min(rect.width - 170, Math.round(event.clientX - rect.left - dragOffset.value.x)));
    const ny = Math.max(10, Math.min(rect.height - 64, Math.round(event.clientY - rect.top - dragOffset.value.y)));

    const nodes = props.modelValue.nodes.map((n) => (n.id === draggingNodeId.value ? { ...n, x: nx, y: ny } : n));
    updateGraph(nodes, props.modelValue.edges);
}

function stopDrag(): void {
    draggingNodeId.value = null;
}

function nodeCenter(nodeId: string): { x: number; y: number } {
    const n = props.modelValue.nodes.find((item) => item.id === nodeId);
    return n ? { x: n.x + 80, y: n.y + 26 } : { x: 0, y: 0 };
}

function edgePath(edge: BpmnEdge): string {
    const a = nodeCenter(edge.source);
    const b = nodeCenter(edge.target);
    const mx = (a.x + b.x) / 2;
    return `M ${a.x} ${a.y} C ${mx} ${a.y}, ${mx} ${b.y}, ${b.x} ${b.y}`;
}
</script>

<template>
    <div class="space-y-3">
        <!-- Palette Bar -->
        <div class="flex flex-wrap items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs">
            <span class="font-semibold text-slate-600">Add BPMN Node:</span>
            <button type="button" @click="addNode('approval')" class="inline-flex items-center gap-1 rounded border border-sky-300 bg-white px-2.5 py-1 font-medium text-sky-900 hover:bg-sky-50">
                <UserCheck class="h-3.5 w-3.5" /> Approval
            </button>
            <button type="button" @click="addNode('conditional_gateway')" class="inline-flex items-center gap-1 rounded border border-amber-300 bg-white px-2.5 py-1 font-medium text-amber-900 hover:bg-amber-50">
                <GitBranch class="h-3.5 w-3.5" /> Condition
            </button>
            <button type="button" @click="addNode('parallel_fork')" class="inline-flex items-center gap-1 rounded border border-indigo-300 bg-white px-2.5 py-1 font-medium text-indigo-900 hover:bg-indigo-50">
                <Plus class="h-3.5 w-3.5" /> Fork
            </button>
            <button type="button" @click="addNode('parallel_join')" class="inline-flex items-center gap-1 rounded border border-indigo-300 bg-white px-2.5 py-1 font-medium text-indigo-900 hover:bg-indigo-50">
                <GitMerge class="h-3.5 w-3.5" /> Join
            </button>
            <button type="button" @click="addNode('archive_worm')" class="inline-flex items-center gap-1 rounded border border-emerald-300 bg-white px-2.5 py-1 font-medium text-emerald-900 hover:bg-emerald-50">
                <Lock class="h-3.5 w-3.5" /> WORM Seal
            </button>
            <button type="button" @click="addNode('end')" class="inline-flex items-center gap-1 rounded border border-slate-300 bg-white px-2.5 py-1 font-medium text-slate-800 hover:bg-slate-100">
                <Square class="h-3.5 w-3.5" /> End
            </button>
        </div>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <!-- Interactive Drag-and-Drop SVG Canvas -->
            <div
                ref="canvasRef"
                @mousemove="onDragMove"
                @mouseup="stopDrag"
                @mouseleave="stopDrag"
                class="relative h-80 overflow-hidden rounded-xl border border-slate-300 bg-slate-950 lg:col-span-2"
                style="background-image: radial-gradient(#334155 1px, transparent 1px); background-size: 18px 18px"
            >
                <svg class="pointer-events-none absolute inset-0 h-full w-full">
                    <defs>
                        <marker id="wf-arrow" markerWidth="8" markerHeight="8" refX="7" refY="3.5" orient="auto">
                            <polygon points="0 0, 8 3.5, 0 7" fill="#38bdf8" />
                        </marker>
                    </defs>
                    <path
                        v-for="edge in modelValue.edges"
                        :key="edge.id"
                        :d="edgePath(edge)"
                        fill="none"
                        stroke="#38bdf8"
                        stroke-width="2"
                        marker-end="url(#wf-arrow)"
                    />
                </svg>

                <div
                    v-for="node in modelValue.nodes"
                    :key="node.id"
                    @mousedown="startDrag($event, node)"
                    :style="{ left: `${node.x}px`, top: `${node.y}px` }"
                    class="absolute w-40 cursor-grab rounded-lg border p-2 text-xs shadow-md select-none active:cursor-grabbing"
                    :class="[
                        selectedNodeId === node.id ? 'ring-2 ring-sky-400' : '',
                        node.type === 'start'
                            ? 'border-emerald-500 bg-emerald-950 text-emerald-100'
                            : node.type === 'end'
                              ? 'border-slate-500 bg-slate-800 text-white'
                              : node.type === 'conditional_gateway'
                                ? 'border-amber-500 bg-amber-950 text-amber-100'
                                : node.type === 'archive_worm'
                                  ? 'border-teal-500 bg-teal-950 text-teal-100'
                                  : 'border-sky-500 bg-slate-900 text-slate-100',
                    ]"
                >
                    <div class="flex items-center justify-between gap-1">
                        <span class="inline-flex items-center gap-1 font-mono text-[9px] uppercase text-slate-400">
                            <Play v-if="node.type === 'start'" class="h-2.5 w-2.5 text-emerald-400" />
                            {{ node.type.replace('_', ' ') }}
                        </span>
                        <button
                            type="button"
                            @mousedown.stop
                            @click="startOrCompleteConnection(node.id)"
                            class="rounded px-1.5 py-0.5 font-mono text-[9px]"
                            :class="connectSourceId === node.id ? 'bg-amber-500 text-slate-950' : 'bg-slate-800 text-sky-300 hover:bg-slate-700'"
                        >
                            {{ connectSourceId === node.id ? 'Pick target' : connectSourceId ? 'Link here' : 'Connect' }}
                        </button>
                    </div>
                    <p class="mt-1 truncate font-semibold text-white">{{ node.label }}</p>
                </div>
            </div>

            <!-- Selected Node & Edge Inspector -->
            <div class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4 text-xs">
                <div v-if="selectedNode" class="space-y-2.5">
                    <div class="flex items-center justify-between">
                        <h3 class="font-bold uppercase tracking-wider text-slate-700">Node Properties</h3>
                        <button
                            v-if="selectedNode.type !== 'start'"
                            type="button"
                            @click="removeNode(selectedNode.id)"
                            class="inline-flex items-center gap-1 text-red-600 hover:underline"
                        >
                            <Trash2 class="h-3.5 w-3.5" /> Remove
                        </button>
                    </div>
                    <div>
                        <label class="mb-1 block font-medium text-slate-600">Step Label</label>
                        <input v-model="selectedNode.label" type="text" class="w-full rounded border border-slate-300 bg-white px-2.5 py-1.5" />
                    </div>
                    <div v-if="selectedNode.type === 'approval'" class="space-y-2">
                        <div>
                            <label class="mb-1 block font-medium text-slate-600">Assignee Role</label>
                            <select v-model="selectedNode.assignee_role" class="w-full rounded border border-slate-300 bg-white px-2 py-1.5">
                                <option v-for="r in roles" :key="r" :value="r">{{ r }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-medium text-slate-600">Or Specific Officer</label>
                            <select v-model="selectedNode.assignee_user_id" class="w-full rounded border border-slate-300 bg-white px-2 py-1.5">
                                <option value="">Auto-select by Role &amp; Clearance</option>
                                <option v-for="u in users" :key="u.id" :value="u.id">
                                    {{ u.rank_title ? `${u.rank_title} ` : '' }}{{ u.name }} (L{{ u.clearance_rank }})
                                </option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block font-medium text-slate-600">Step SLA (Hours)</label>
                            <input v-model.number="selectedNode.sla_hours" type="number" min="1" max="720" class="w-full rounded border border-slate-300 bg-white px-2.5 py-1.5" />
                        </div>
                    </div>
                </div>

                <div class="border-t border-slate-200 pt-3">
                    <h4 class="mb-2 font-bold uppercase tracking-wider text-slate-700">Transitions ({{ modelValue.edges.length }})</h4>
                    <div class="max-h-36 space-y-2 overflow-y-auto">
                        <div v-for="edge in modelValue.edges" :key="edge.id" class="rounded border border-slate-200 bg-white p-2">
                            <div class="flex items-center justify-between font-mono text-[10px] text-slate-600">
                                <span>{{ edge.source }} -> {{ edge.target }}</span>
                                <button type="button" @click="removeEdge(edge.id)" class="text-red-600 hover:underline">Del</button>
                            </div>
                            <input
                                v-model="edge.condition"
                                type="text"
                                placeholder="Optional: classification_rank >= 2"
                                class="mt-1 w-full rounded border border-slate-200 px-2 py-1 font-mono text-[10px]"
                            />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
