<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { KeyRound } from '@lucide/vue';

const form = useForm({
    code: '',
});

function submit(): void {
    form.post('/mfa-challenge');
}
</script>

<template>
    <Head title="MFA Challenge" />
    <div class="flex min-h-screen items-center justify-center bg-slate-950 p-4 text-slate-100">
        <div class="w-full max-w-md rounded-xl border border-slate-800 bg-slate-900 p-6 shadow-xl">
            <div class="mb-5 flex items-center gap-3">
                <div class="rounded-lg bg-sky-600/20 p-2.5 text-sky-400">
                    <KeyRound class="h-6 w-6" />
                </div>
                <div>
                    <h1 class="text-base font-bold text-white">Offline MFA Verification</h1>
                    <p class="text-xs text-slate-400">Enter your 6-digit TOTP authenticator code or an 8-char recovery code.</p>
                </div>
            </div>

            <form @submit.prevent="submit" class="space-y-4">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-300">Verification Code</label>
                    <input
                        v-model="form.code"
                        type="text"
                        required
                        autofocus
                        placeholder="123456"
                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-base tracking-widest text-white focus:border-sky-500 focus:outline-none"
                    />
                    <p v-if="form.errors.code" class="mt-1 text-xs text-red-400">{{ form.errors.code }}</p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-lg bg-sky-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-sky-500 disabled:opacity-50"
                >
                    Verify &amp; Enter Vault
                </button>
            </form>
        </div>
    </div>
</template>
