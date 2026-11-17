<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps<{
    secret: string;
    qrSvg: string;
    alreadyConfigured: boolean;
}>();

const enrollForm = useForm({
    code: '',
});

const stepUpForm = useForm({
    code: '',
});

function confirmEnroll(): void {
    enrollForm.post('/security/mfa/confirm');
}

function submitStepUp(): void {
    stepUpForm.post('/security/mfa/step-up', {
        onSuccess: () => stepUpForm.reset(),
    });
}
</script>

<template>
    <Head title="MFA & Security Devices" />
    <AppLayout>
        <div class="mx-auto max-w-4xl space-y-6">
            <div>
                <h1 class="text-xl font-bold text-slate-900">Offline Multi-Factor Authentication</h1>
                <p class="text-sm text-slate-600">
                    QR codes are rendered locally via BaconQrCode SVG with zero external API calls.
                </p>
            </div>

            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                    <div class="mb-4 flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <KeyRound class="h-4 w-4 text-sky-600" />
                        <span>{{ alreadyConfigured ? 'Re-Enroll TOTP Authenticator' : 'Enroll TOTP Authenticator (Required)' }}</span>
                    </div>

                    <div class="mb-4 flex justify-center rounded-lg border border-slate-200 bg-slate-50 p-4" v-html="qrSvg" />

                    <div class="mb-4 rounded-lg bg-slate-100 p-3">
                        <p class="text-[11px] font-medium uppercase text-slate-500">Manual Base32 Secret</p>
                        <p class="font-mono text-xs font-semibold text-slate-900 break-all">{{ secret }}</p>
                    </div>

                    <form @submit.prevent="confirmEnroll" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">Confirm 6-Digit Code</label>
                            <input
                                v-model="enrollForm.code"
                                type="text"
                                required
                                placeholder="000000"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm"
                            />
                            <p v-if="enrollForm.errors.code" class="mt-1 text-xs text-red-600">{{ enrollForm.errors.code }}</p>
                        </div>
                        <button
                            type="submit"
                            :disabled="enrollForm.processing"
                            class="w-full rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                        >
                            Save &amp; Activate TOTP
                        </button>
                    </form>
                </div>

                <div class="rounded-xl border border-slate-200 bg-white p-6 shadow-xs">
                    <div class="mb-3 flex items-center gap-2 text-sm font-semibold text-slate-900">
                        <ShieldCheck class="h-4 w-4 text-emerald-600" />
                        <span>Step-Up Verification (Two-Person / Break-Glass)</span>
                    </div>
                    <p class="mb-4 text-xs text-slate-600">
                        Co-signing declassification, deletion, or emergency break-glass requires a fresh MFA assertion within the past 5 minutes.
                    </p>
                    <form @submit.prevent="submitStepUp" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-slate-700">Current Authenticator / Recovery Code</label>
                            <input
                                v-model="stepUpForm.code"
                                type="text"
                                required
                                placeholder="123456"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2 font-mono text-sm"
                            />
                            <p v-if="stepUpForm.errors.code" class="mt-1 text-xs text-red-600">{{ stepUpForm.errors.code }}</p>
                        </div>
                        <button
                            type="submit"
                            :disabled="stepUpForm.processing"
                            class="w-full rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white hover:bg-sky-500"
                        >
                            Verify Step-Up Session
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
