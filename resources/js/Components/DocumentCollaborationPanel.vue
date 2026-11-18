<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, Eraser, MessageSquare, Radio, ShieldAlert, Trash2 } from '@lucide/vue';
import { BetterDocReverbClient, type PresenceMember } from '@/lib/reverbClient';
import type { DocumentAnnotationItem, DocumentCommentItem, SharedProps } from '@/types/betterdoc';

const props = defineProps<{
    documentId: string;
    currentVersionId: string | null;
    comments: DocumentCommentItem[];
    annotations: DocumentAnnotationItem[];
    shareableUsers: { id: string; name: string; email: string; rank_title: string | null; clearance_rank: number }[];
    canEdit: boolean;
    isWorm: boolean;
}>();

const page = usePage<{ props: SharedProps }>();
const shared = computed(() => page.props as unknown as SharedProps);

const presenceMembers = ref<PresenceMember[]>([]);
let reverbClient: BetterDocReverbClient | null = null;

const commentForm = useForm({
    body: '',
    parent_id: '' as string | null,
    mentioned_user_ids: [] as string[],
});

const pendingRedactions = computed(() =>
    props.annotations.filter(
        (a) => a.kind === 'redaction_mark' && a.redacted_in_version_id === null && a.version_id === props.currentVersionId
    )
);

function submitComment(): void {
    commentForm.post(`/documents/${props.documentId}/comments`, {
        preserveScroll: true,
        onSuccess: () => {
            commentForm.reset();
        },
    });
}

function toggleMentionUser(userId: string, handle: string): void {
    if (!commentForm.mentioned_user_ids.includes(userId)) {
        commentForm.mentioned_user_ids.push(userId);
        const prefix = commentForm.body.trim() === '' ? '' : ' ';
        commentForm.body += `${prefix}@${handle}`;
    } else {
        commentForm.mentioned_user_ids = commentForm.mentioned_user_ids.filter((id) => id !== userId);
    }
}

function resolveComment(commentId: string): void {
    router.post(`/documents/${props.documentId}/comments/${commentId}/resolve`, {}, { preserveScroll: true });
}

function commitRedactions(): void {
    router.post(`/documents/${props.documentId}/redactions/apply`, {}, { preserveScroll: true });
}

function deleteAnnotation(id: string): void {
    router.delete(`/documents/${props.documentId}/annotations/${id}`, { preserveScroll: true });
}

onMounted(() => {
    const revCfg = shared.value.app.reverb;
    if (revCfg?.enabled) {
        reverbClient = new BetterDocReverbClient(revCfg);
        reverbClient.connect();
        reverbClient.subscribe(
            `presence-document.${props.documentId}`,
            (eventName) => {
                if (eventName === 'betterdoc.document.activity') {
                    router.reload({ only: ['comments', 'annotations', 'document'] });
                }
            },
            (members) => {
                presenceMembers.value = members;
            }
        );
    }
});

onUnmounted(() => {
    reverbClient?.disconnect();
});
</script>

<template>
    <div class="space-y-6">
        <!-- Pending Annotations & True Destructive Redaction Control -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <Eraser class="h-4 w-4 text-red-600" />
                    <h2 class="text-xs font-bold uppercase tracking-wider text-slate-700">Annotations &amp; Destructive PyMuPDF Redaction</h2>
                </div>
                <button
                    v-if="canEdit && !isWorm && pendingRedactions.length > 0"
                    type="button"
                    @click="commitRedactions"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-500"
                >
                    <Eraser class="h-3.5 w-3.5" /> Commit {{ pendingRedactions.length }} Redaction(s)
                </button>
            </div>

            <p v-if="annotations.length === 0" class="text-xs text-slate-500">
                Use the viewer toolbar above (Highlight, Note, or Mark Redaction) to drag regions directly onto the PDF pages.
            </p>
            <div v-else class="divide-y divide-slate-100 text-xs">
                <div v-for="ann in annotations" :key="ann.id" class="flex items-center justify-between gap-3 py-2">
                    <div class="space-y-0.5">
                        <div class="flex items-center gap-2">
                            <span
                                class="rounded px-1.5 py-0.5 font-mono text-[10px] font-bold uppercase"
                                :class="
                                    ann.kind === 'redaction_mark'
                                        ? 'bg-red-100 text-red-800'
                                        : ann.kind === 'highlight'
                                          ? 'bg-amber-100 text-amber-900'
                                          : 'bg-sky-100 text-sky-900'
                                "
                            >
                                {{ ann.kind.replace('_', ' ') }}
                            </span>
                            <span class="font-mono text-slate-600">Page {{ ann.page_number }}</span>
                            <span v-if="ann.redacted_in_version_id" class="rounded bg-emerald-100 px-1.5 py-0.5 text-[10px] font-semibold text-emerald-800">
                                Purged in PDF
                            </span>
                        </div>
                        <p class="text-slate-700">{{ ann.content ?? 'No note attached' }} · by {{ ann.creator_name }}</p>
                    </div>
                    <button
                        v-if="canEdit && !isWorm && !ann.redacted_in_version_id"
                        type="button"
                        @click="deleteAnnotation(ann.id)"
                        class="rounded p-1 text-slate-400 hover:bg-slate-100 hover:text-red-600"
                        title="Remove annotation mark"
                    >
                        <Trash2 class="h-3.5 w-3.5" />
                    </button>
                </div>
            </div>
        </div>

        <!-- Comments & Clearance-Filtered @Mentions -->
        <div class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div class="flex items-center gap-2">
                    <MessageSquare class="h-4 w-4 text-sky-600" />
                    <h2 class="text-xs font-bold uppercase tracking-wider text-slate-700">Comments &amp; Clearance-Safe @Mentions</h2>
                </div>
                <span v-if="presenceMembers.length > 0" class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-medium text-emerald-800">
                    <Radio class="h-3 w-3 text-emerald-600" /> {{ presenceMembers.length }} cleared active viewer(s)
                </span>
            </div>

            <form @submit.prevent="submitComment" class="mb-4 space-y-2">
                <textarea
                    v-model="commentForm.body"
                    rows="2"
                    required
                    placeholder="Add review comment... (@service_number or click recipient pills below)"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-xs focus:border-sky-500 focus:outline-none"
                />
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-[11px] text-slate-500">@Mention:</span>
                        <button
                            v-for="u in shareableUsers.slice(0, 6)"
                            :key="u.id"
                            type="button"
                            @click="toggleMentionUser(u.id, u.email.split('@')[0])"
                            class="rounded border px-2 py-0.5 text-[11px] font-medium transition"
                            :class="
                                commentForm.mentioned_user_ids.includes(u.id)
                                    ? 'border-sky-500 bg-sky-50 text-sky-800'
                                    : 'border-slate-200 bg-slate-50 text-slate-700 hover:bg-slate-100'
                            "
                        >
                            {{ u.rank_title ? `${u.rank_title} ` : '' }}{{ u.name }} (L{{ u.clearance_rank }})
                        </button>
                    </div>
                    <button
                        type="submit"
                        :disabled="commentForm.processing"
                        class="rounded-lg bg-slate-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                    >
                        Post Comment
                    </button>
                </div>
                <p class="flex items-center gap-1 text-[11px] text-slate-500">
                    <ShieldAlert class="h-3.5 w-3.5 text-amber-600" />
                    <span>Notifications for @mentioned officers without MAC/compartment clearance are silently suppressed.</span>
                </p>
            </form>

            <div v-if="comments.length === 0" class="text-xs text-slate-500">No comments yet.</div>
            <div v-else class="space-y-3">
                <div v-for="c in comments" :key="c.id" class="rounded-lg border border-slate-200 p-3 text-xs" :class="c.resolved_at ? 'bg-slate-50 opacity-75' : 'bg-white'">
                    <div class="flex items-center justify-between gap-2">
                        <span class="font-semibold text-slate-900">{{ c.author_name }}</span>
                        <div class="flex items-center gap-2">
                            <span v-if="c.suppressed_mentions_count > 0" class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] font-medium text-amber-900">
                                {{ c.suppressed_mentions_count }} uncleared mention(s) suppressed
                            </span>
                            <button type="button" @click="resolveComment(c.id)" class="inline-flex items-center gap-1 text-[11px] font-medium text-sky-700 hover:underline">
                                <CheckCircle2 class="h-3 w-3" /> {{ c.resolved_at ? 'Reopen' : 'Resolve' }}
                            </button>
                        </div>
                    </div>
                    <p class="mt-1 whitespace-pre-line text-slate-700">{{ c.body }}</p>
                </div>
            </div>
        </div>
    </div>
</template>
