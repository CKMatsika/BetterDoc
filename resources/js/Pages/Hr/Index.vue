<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Lock, ShieldCheck, UserPlus, Users, FilePlus2 } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps<{
    employeeFiles: {
        id: string;
        subject_user_id: string;
        subject_name: string;
        subject_email: string;
        employee_number: string;
        department_name: string;
        job_title: string;
        hire_date: string | null;
        status: string;
    }[];
    hrDocuments: {
        id: string;
        doc_number: string;
        title: string;
        classification_rank: number;
        hr_subject_user_id: string;
        hr_restricted_from_subject: boolean;
        category: string;
    }[];
    tenantUsers: { id: string; name: string; email: string }[];
    cabinets: { id: string; name: string }[];
    allowedClearances: { id: string; rank: number; name: string }[];
    canManageHr: boolean;
}>();

const empForm = useForm({
    subject_user_id: props.tenantUsers[0]?.id ?? '',
    cabinet_id: props.cabinets[0]?.id ?? '',
    employee_number: '',
    department_name: '',
    job_title: '',
    hire_date: new Date().toISOString().slice(0, 10),
});

const recordForm = useForm({
    hr_employee_file_id: props.employeeFiles[0]?.id ?? '',
    cabinet_id: props.cabinets[0]?.id ?? '',
    classification_level_id: props.allowedClearances[0]?.id ?? '',
    category: 'payslip',
    title: '',
    content_text: '',
    restricted_from_subject: false,
});

function onCategoryChange(): void {
    recordForm.restricted_from_subject = ['disciplinary', 'medical', 'investigation'].includes(recordForm.category);
}

function submitEmployee(): void {
    empForm.post('/hr/employees', {
        preserveScroll: true,
        onSuccess: () => empForm.reset('employee_number', 'department_name', 'job_title'),
    });
}

function submitRecord(): void {
    recordForm.post('/hr/records', {
        preserveScroll: true,
        onSuccess: () => recordForm.reset('title', 'content_text'),
    });
}
</script>

<template>
    <Head title="HR Personnel Vault" />
    <AppLayout>
        <div class="space-y-6">
            <div>
                <h1 class="text-xl font-bold text-slate-900">HR Personnel Vault & Subject Isolation</h1>
                <p class="text-xs text-slate-600">
                    PostgreSQL RLS enforces <code class="font-mono">hr_restricted_from_subject = true</code> across Vault, Full-Text Search & AI RAG.
                </p>
            </div>

            <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-xs text-sky-900 flex items-start gap-3">
                <Lock class="h-5 w-5 shrink-0 text-sky-700 mt-0.5" />
                <div>
                    <strong>Zero-Leak Subject Isolation:</strong> Employees can view their own Payslips & Employment Contracts, while HR Disciplinary, Medical, and Investigation records marked shielded remain completely non-discoverable (404 / zero search & AI citations) to the subject employee.
                </div>
            </div>

            <!-- Employee Files -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <div v-for="emp in employeeFiles" :key="emp.id" class="rounded-xl border border-slate-200 bg-white p-4 shadow-xs space-y-1">
                    <div class="flex items-center justify-between">
                        <span class="font-mono text-xs font-bold text-sky-700">{{ emp.employee_number }}</span>
                        <span class="rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-semibold text-emerald-800">{{ emp.status }}</span>
                    </div>
                    <p class="text-sm font-bold text-slate-900">{{ emp.subject_name }}</p>
                    <p class="text-xs text-slate-600">{{ emp.job_title }} · {{ emp.department_name }}</p>
                    <p class="text-[11px] font-mono text-slate-400">{{ emp.subject_email }}</p>
                </div>
            </div>

            <!-- HR Documents Table -->
            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-xs">
                <div class="border-b border-slate-200 bg-slate-50 px-4 py-3 flex items-center gap-2 text-xs font-bold text-slate-700">
                    <Users class="h-4 w-4 text-slate-500" />
                    Visible HR Personnel Records ({{ hrDocuments.length }})
                </div>
                <table class="min-w-full divide-y divide-slate-200 text-xs">
                    <thead class="bg-slate-50 text-left font-semibold uppercase tracking-wider text-slate-500">
                        <tr>
                            <th class="px-4 py-3">Document #</th>
                            <th class="px-4 py-3">Title</th>
                            <th class="px-4 py-3">Category</th>
                            <th class="px-4 py-3">Subject Policy</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="doc in hrDocuments" :key="doc.id" class="hover:bg-slate-50">
                            <td class="px-4 py-2.5 font-mono font-semibold">
                                <Link :href="`/documents/${doc.id}`" class="text-sky-700 hover:underline">{{ doc.doc_number }}</Link>
                            </td>
                            <td class="px-4 py-2.5 font-semibold text-slate-900">{{ doc.title }}</td>
                            <td class="px-4 py-2.5 uppercase font-mono text-slate-600">{{ doc.category }} (L{{ doc.classification_rank }})</td>
                            <td class="px-4 py-2.5">
                                <span
                                    v-if="doc.hr_restricted_from_subject"
                                    class="inline-flex items-center gap-1 rounded-full bg-red-100 px-2 py-0.5 text-[11px] font-semibold text-red-800"
                                >
                                    <Lock class="h-3 w-3" /> Shielded from Subject
                                </span>
                                <span
                                    v-else
                                    class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-800"
                                >
                                    <ShieldCheck class="h-3 w-3" /> Visible to Employee
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <!-- HR Management Forms -->
            <div v-if="canManageHr" class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <form @submit.prevent="submitEmployee" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-3">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <UserPlus class="h-4 w-4 text-sky-600" />
                        Provision Employee Personnel Folder
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <select v-model="empForm.subject_user_id" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                            <option v-for="u in tenantUsers" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                        </select>
                        <select v-model="empForm.cabinet_id" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                            <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <input v-model="empForm.employee_number" placeholder="Employee # (EMP-042)" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                        <input v-model="empForm.department_name" placeholder="Department" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                        <input v-model="empForm.job_title" placeholder="Job Title" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                        <input v-model="empForm.hire_date" type="date" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    </div>
                    <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                        Create Personnel Folder
                    </button>
                </form>

                <form @submit.prevent="submitRecord" class="rounded-xl border border-slate-200 bg-white p-5 shadow-xs space-y-3">
                    <div class="flex items-center gap-2 text-sm font-bold text-slate-900">
                        <FilePlus2 class="h-4 w-4 text-sky-600" />
                        File HR Record
                    </div>
                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <select v-model="recordForm.hr_employee_file_id" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                            <option v-for="emp in employeeFiles" :key="emp.id" :value="emp.id">
                                {{ emp.employee_number }} — {{ emp.subject_name }}
                            </option>
                        </select>
                        <select v-model="recordForm.category" @change="onCategoryChange" class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                            <option value="payslip">Payslip (Visible)</option>
                            <option value="contract">Employment Contract (Visible)</option>
                            <option value="disciplinary">Disciplinary Hearing (Shielded)</option>
                            <option value="medical">Medical Record (Shielded)</option>
                            <option value="investigation">Internal Investigation (Shielded)</option>
                        </select>
                        <select v-model="recordForm.cabinet_id" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                            <option v-for="c in cabinets" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                        <select v-model="recordForm.classification_level_id" required class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs">
                            <option v-for="lvl in allowedClearances" :key="lvl.id" :value="lvl.id">L{{ lvl.rank }} — {{ lvl.name }}</option>
                        </select>
                    </div>
                    <input v-model="recordForm.title" placeholder="Record Title" required class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    <textarea v-model="recordForm.content_text" rows="2" placeholder="Record body (encrypted & OCR/RAG indexed under RLS)..." required class="w-full rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs" />
                    <div class="flex items-center justify-between">
                        <label class="flex items-center gap-2 text-xs text-slate-700">
                            <input v-model="recordForm.restricted_from_subject" type="checkbox" class="rounded border-slate-300" />
                            Shield from Subject Employee (<code class="font-mono">hr_restricted_from_subject</code>)
                        </label>
                        <button type="submit" class="rounded-lg bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                            File HR Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </AppLayout>
</template>
