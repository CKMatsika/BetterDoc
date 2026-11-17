<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileEdit, Lock, ShieldCheck } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{
    document: {
        id: string;
        doc_number: string;
        title: string;
        classification_rank: number;
        is_worm: boolean;
        filename: string;
        mime_type: string;
    };
    wopiSession: {
        access_token: string;
        token_ttl: number;
        wopi_src: string;
        collabora_iframe_url: string | null;
        can_write: boolean;
        expires_at: string;
    };
    collaboraEnabled: boolean;
}>();
</script>

<template>
    <Head :title="`Co-Edit: ${document.doc_number}`" />
    <AppLayout>
        <div class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <Link :href="`/documents/${document.id}`" class="mb-1 inline-flex items-center gap-1 text-xs font-medium text-slate-500 hover:text-slate-900">
                        <ArrowLeft class="h-3.5 w-3.5" /> Back to Document
                    </Link>
                    <h1 class="text-lg font-bold text-slate-900">Offline Co-Editing Session (Collabora Online / WOPI)</h1>
                    <p class="font-mono text-xs text-slate-600">
                        {{ document.doc_number }} · {{ document.filename }} · Mode: {{ wopiSession.can_write ? 'Read-Write (Locked)' : 'Read-Only (MAC / WORM)' }}
                    </p>
                </div>

                <div class="flex items-center gap-2 text-xs">
                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-3 py-1 font-semibold text-emerald-800">
                        <ShieldCheck class="h-3.5 w-3.5" /> RLS + MAC WOPI Token Bound
                    </span>
                    <span v-if="document.is_worm" class="inline-flex items-center gap-1 rounded-full bg-slate-900 px-3 py-1 font-semibold text-white">
                        <Lock class="h-3.5 w-3.5" /> WORM Immutable
                    </span>
                </div>
            </div>

            <div v-if="collaboraEnabled && wopiSession.collabora_iframe_url" class="overflow-hidden rounded-xl border border-slate-300 bg-white shadow-sm">
                <iframe
                    :src="wopiSession.collabora_iframe_url"
                    title="Collabora Online Offline Editor"
                    class="h-[720px] w-full border-0"
                    allowfullscreen
                />
            </div>

            <div v-else class="rounded-xl border border-slate-200 bg-white p-6 shadow-xs text-xs space-y-3">
                <div class="flex items-center gap-2 font-bold text-slate-900">
                    <FileEdit class="h-4 w-4 text-sky-600" />
                    <span>WOPI Co-Editing Host Ready (Collabora Container Disabled in Standalone Dev Mode)</span>
                </div>
                <p class="text-slate-600">
                    The Laravel WOPI Host endpoints (<code class="font-mono">CheckFileInfo</code>, <code class="font-mono">GetFile</code>, <code class="font-mono">PutFile</code>, and <code class="font-mono">Lock/Refresh/Unlock</code>) have issued an active RLS-enforced WOPI token for this session. Start the offline <code class="font-mono">collabora</code> container via Docker Compose and set <code class="font-mono">SENTINEL_COLLABORA_ENABLED=true</code> to render the live document editor frame here.
                </p>
                <div class="rounded-lg border border-slate-200 bg-slate-950 p-4 font-mono text-[11px] text-slate-200 space-y-1">
                    <div>WOPISrc: {{ wopiSession.wopi_src }}</div>
                    <div>Access Token Prefix: {{ wopiSession.access_token.slice(0, 16) }}... (Expires: {{ wopiSession.expires_at }})</div>
                    <div>UserCanWrite: {{ wopiSession.can_write }}</div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
