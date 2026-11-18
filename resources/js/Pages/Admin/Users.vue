<script setup lang="ts">
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Building2, KeyRound, Palette, ShieldCheck, UserCog, UserPlus, Users, X } from '@lucide/vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import ClassificationBadge from '@/Components/ClassificationBadge.vue';

interface ManagedUser {
    id: string;
    name: string;
    email: string;
    service_number: string | null;
    rank_title: string | null;
    status: 'active' | 'suspended';
    clearance_level_id: string;
    clearance_rank: number;
    clearance_name: string | null;
    clearance_color: string | null;
    org_unit_id: string | null;
    org_unit_name: string | null;
    compartment_ids: string[];
    compartment_codes: string[];
    roles: string[];
    permissions: string[];
    direct_permissions: string[];
    mfa_enforced: boolean;
}

interface OrgUnitOption {
    id: string;
    code: string;
    name: string;
    unit_type: string;
}

interface ClearanceOption {
    id: string;
    rank: number;
    code: string;
    name: string;
    color_hex: string;
}

interface CompartmentOption {
    id: string;
    code: string;
    name: string;
}

const props = defineProps<{
    users: ManagedUser[];
    roles: string[];
    permissionsCatalog: string[];
    orgUnits: OrgUnitOption[];
    clearanceLevels: ClearanceOption[];
    compartments: CompartmentOption[];
}>();

const roleDescriptions: Record<string, string> = {
    SystemAdmin: 'Full tenant governance: branding, users, roles, security profiles & DR drills.',
    Approver: 'Approve workflows, unlock dual-person reviews, and act as approver in routes.',
    RecordsOfficer: 'Manage official registries, retention policies, holds, and compliance exports.',
    Author: 'Create, upload, edit, collaborate, and initiate document approval workflows.',
    Reader: 'Read-only access to documents cleared for their rank, unit & compartments.',
    Auditor: 'Read-only inspection across immutable audit ledgers & compliance verification packs.',
};

const defaultClearanceId = computed(
    () => props.clearanceLevels[1]?.id ?? props.clearanceLevels[0]?.id ?? '',
);
const defaultOrgUnitId = computed(() => props.orgUnits[0]?.id ?? '');

const deptForm = useForm({
    code: '',
    name: '',
    unit_type: 'department',
    parent_id: '',
});

const createForm = useForm<{
    name: string;
    email: string;
    password: string;
    service_number: string;
    rank_title: string;
    org_unit_id: string;
    clearance_level_id: string;
    compartment_ids: string[];
    roles: string[];
    permissions: string[];
    mfa_enforced: boolean;
}>({
    name: '',
    email: '',
    password: '',
    service_number: '',
    rank_title: '',
    org_unit_id: defaultOrgUnitId.value,
    clearance_level_id: defaultClearanceId.value,
    compartment_ids: [],
    roles: ['Author'],
    permissions: ['documents.view', 'documents.create'],
    mfa_enforced: false,
});

const editingUser = ref<ManagedUser | null>(null);
const editForm = useForm<{
    clearance_level_id: string;
    org_unit_id: string;
    compartment_ids: string[];
    roles: string[];
    permissions: string[];
    status: 'active' | 'suspended';
    mfa_enforced: boolean;
    password: string;
}>({
    clearance_level_id: '',
    org_unit_id: '',
    compartment_ids: [],
    roles: ['Author'],
    permissions: [],
    status: 'active',
    mfa_enforced: false,
    password: '',
});

function createDepartment(): void {
    deptForm.post('/admin/org-units', {
        preserveScroll: true,
        onSuccess: () => deptForm.reset('code', 'name'),
    });
}

function createUser(): void {
    createForm.post('/admin/users', {
        preserveScroll: true,
        onSuccess: () => {
            createForm.reset('name', 'email', 'password', 'service_number', 'rank_title');
            createForm.roles = ['Author'];
            createForm.permissions = ['documents.view', 'documents.create'];
        },
    });
}

function toggleStringItem(list: string[], value: string): void {
    const idx = list.indexOf(value);
    if (idx === -1) {
        list.push(value);
    } else {
        list.splice(idx, 1);
    }
}

function startEditUser(u: ManagedUser): void {
    editingUser.value = u;
    editForm.clearance_level_id = u.clearance_level_id;
    editForm.org_unit_id = u.org_unit_id ?? defaultOrgUnitId.value;
    editForm.compartment_ids = [...u.compartment_ids];
    editForm.roles = [...u.roles];
    editForm.permissions = [...u.direct_permissions];
    editForm.status = u.status;
    editForm.mfa_enforced = u.mfa_enforced;
    editForm.password = '';
}

function saveUserAccess(): void {
    if (!editingUser.value) {
        return;
    }

    editForm.patch(`/admin/users/${editingUser.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editingUser.value = null;
            editForm.password = '';
        },
    });
}
</script>

<template>
    <Head title="Users, Roles & Permissions" />

    <AppLayout>
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-xl font-bold text-slate-900">Super Admin &gt; Users, Roles &amp; Permissions</h1>
                    <p class="text-xs text-slate-600">
                        Create organisational departments, provision user accounts, assign Spatie roles, grant granular permissions, and enforce security clearances.
                    </p>
                </div>
                <Link
                    href="/settings/branding"
                    class="inline-flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-3.5 py-2 text-xs font-semibold text-slate-800 hover:bg-slate-50"
                >
                    <Palette class="h-3.5 w-3.5 text-[#0E7C66]" />
                    Organisation Branding &amp; Portal
                </Link>
            </div>

            <!-- Summary KPIs -->
            <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                <div class="rounded-[14px] border border-slate-200 bg-white p-4 shadow-xs">
                    <p class="text-xs font-semibold text-slate-500 uppercase">Total Users</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ users.length }}</p>
                </div>
                <div class="rounded-[14px] border border-slate-200 bg-white p-4 shadow-xs">
                    <p class="text-xs font-semibold text-slate-500 uppercase">Departments</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ orgUnits.length }}</p>
                </div>
                <div class="rounded-[14px] border border-slate-200 bg-white p-4 shadow-xs">
                    <p class="text-xs font-semibold text-slate-500 uppercase">Roles</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ roles.length }}</p>
                </div>
                <div class="rounded-[14px] border border-slate-200 bg-white p-4 shadow-xs">
                    <p class="text-xs font-semibold text-slate-500 uppercase">Granular Permissions</p>
                    <p class="mt-1 text-2xl font-bold text-slate-900">{{ permissionsCatalog.length }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-6 xl:grid-cols-12">
                <!-- Left Column: Department Builder & Create User -->
                <div class="space-y-6 xl:col-span-5">
                    <!-- Add Department -->
                    <section class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                        <div class="flex items-center gap-2 border-b border-slate-100 pb-3">
                            <Building2 class="h-4 w-4 text-[#0E7C66]" />
                            <h2 class="text-sm font-bold text-slate-900">Create Department / Unit</h2>
                        </div>

                        <div class="flex flex-wrap gap-1.5">
                            <span
                                v-for="unit in orgUnits"
                                :key="unit.id"
                                class="rounded-md border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-700"
                            >
                                <strong class="font-mono text-slate-900">{{ unit.code }}</strong> · {{ unit.name }}
                            </span>
                        </div>

                        <form @submit.prevent="createDepartment" class="grid grid-cols-1 gap-3 sm:grid-cols-12">
                            <div class="sm:col-span-4">
                                <label for="dept-code" class="block text-xs font-semibold text-slate-700">Code *</label>
                                <input
                                    id="dept-code"
                                    v-model="deptForm.code"
                                    type="text"
                                    required
                                    placeholder="FIN"
                                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm uppercase"
                                />
                                <p v-if="deptForm.errors.code" class="mt-1 text-xs text-red-700">{{ deptForm.errors.code }}</p>
                            </div>
                            <div class="sm:col-span-8">
                                <label for="dept-name" class="block text-xs font-semibold text-slate-700">Department Name *</label>
                                <input
                                    id="dept-name"
                                    v-model="deptForm.name"
                                    type="text"
                                    required
                                    placeholder="Finance & Treasury"
                                    class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                />
                                <p v-if="deptForm.errors.name" class="mt-1 text-xs text-red-700">{{ deptForm.errors.name }}</p>
                            </div>
                            <div class="sm:col-span-12">
                                <button
                                    type="submit"
                                    :disabled="deptForm.processing"
                                    class="rounded-lg border border-slate-300 bg-slate-900 px-4 py-2 text-xs font-semibold text-white hover:bg-slate-800 disabled:opacity-50"
                                >
                                    Add Department
                                </button>
                            </div>
                        </form>
                    </section>

                    <!-- Create User -->
                    <section class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                        <div class="flex items-center gap-2 border-b border-slate-100 pb-3">
                            <UserPlus class="h-4 w-4 text-[#0E7C66]" />
                            <h2 class="text-sm font-bold text-slate-900">Create User &amp; Assign Roles</h2>
                        </div>

                        <form @submit.prevent="createUser" class="space-y-4">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                <div>
                                    <label for="new-name" class="block text-xs font-semibold text-slate-700">Full Name *</label>
                                    <input
                                        id="new-name"
                                        v-model="createForm.name"
                                        type="text"
                                        required
                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                    />
                                    <p v-if="createForm.errors.name" class="mt-1 text-xs text-red-700">{{ createForm.errors.name }}</p>
                                </div>
                                <div>
                                    <label for="new-email" class="block text-xs font-semibold text-slate-700">Email *</label>
                                    <input
                                        id="new-email"
                                        v-model="createForm.email"
                                        type="email"
                                        required
                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                    />
                                    <p v-if="createForm.errors.email" class="mt-1 text-xs text-red-700">{{ createForm.errors.email }}</p>
                                </div>
                                <div>
                                    <label for="new-password" class="block text-xs font-semibold text-slate-700">Initial Password *</label>
                                    <input
                                        id="new-password"
                                        v-model="createForm.password"
                                        type="password"
                                        required
                                        minlength="8"
                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                    />
                                    <p v-if="createForm.errors.password" class="mt-1 text-xs text-red-700">{{ createForm.errors.password }}</p>
                                </div>
                                <div>
                                    <label for="new-title" class="block text-xs font-semibold text-slate-700">Job / Rank Title</label>
                                    <input
                                        id="new-title"
                                        v-model="createForm.rank_title"
                                        type="text"
                                        placeholder="e.g. Records Manager"
                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                    />
                                </div>
                                <div>
                                    <label for="new-unit" class="block text-xs font-semibold text-slate-700">Department</label>
                                    <select
                                        id="new-unit"
                                        v-model="createForm.org_unit_id"
                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                    >
                                        <option v-for="u in orgUnits" :key="u.id" :value="u.id">
                                            {{ u.code }} — {{ u.name }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label for="new-clearance" class="block text-xs font-semibold text-slate-700">Clearance Level *</label>
                                    <select
                                        id="new-clearance"
                                        v-model="createForm.clearance_level_id"
                                        required
                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                    >
                                        <option v-for="lvl in clearanceLevels" :key="lvl.id" :value="lvl.id">
                                            L{{ lvl.rank }} — {{ lvl.name }}
                                        </option>
                                    </select>
                                </div>
                            </div>

                            <!-- Roles Selection -->
                            <div>
                                <p class="text-xs font-semibold text-slate-700">Assign Roles * (select at least one)</p>
                                <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                                    <label
                                        v-for="role in roles"
                                        :key="role"
                                        class="flex items-start gap-2 rounded-lg border border-slate-200 p-2.5 text-xs hover:bg-slate-50 cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="createForm.roles.includes(role)"
                                            @change="toggleStringItem(createForm.roles, role)"
                                            class="mt-0.5 rounded border-slate-300"
                                        />
                                        <span>
                                            <strong class="block text-slate-900">{{ role }}</strong>
                                            <span class="text-[11px] text-slate-500">{{ roleDescriptions[role] ?? '' }}</span>
                                        </span>
                                    </label>
                                </div>
                                <p v-if="createForm.errors.roles" class="mt-1 text-xs text-red-700">{{ createForm.errors.roles }}</p>
                            </div>

                            <!-- Granular Permissions -->
                            <div>
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-semibold text-slate-700">Direct Granular Permissions</p>
                                    <div class="flex gap-2 text-[11px]">
                                        <button
                                            type="button"
                                            @click="createForm.permissions = [...permissionsCatalog]"
                                            class="font-semibold text-[#0E7C66] hover:underline"
                                        >
                                            Select all
                                        </button>
                                        <button
                                            type="button"
                                            @click="createForm.permissions = []"
                                            class="font-semibold text-slate-500 hover:underline"
                                        >
                                            Clear
                                        </button>
                                    </div>
                                </div>
                                <div class="mt-2 grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                                    <label
                                        v-for="perm in permissionsCatalog"
                                        :key="perm"
                                        class="flex items-center gap-1.5 rounded border border-slate-200 px-2 py-1 text-[11px] font-mono text-slate-700 hover:bg-slate-50 cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="createForm.permissions.includes(perm)"
                                            @change="toggleStringItem(createForm.permissions, perm)"
                                            class="rounded border-slate-300"
                                        />
                                        <span class="truncate">{{ perm }}</span>
                                    </label>
                                </div>
                            </div>

                            <!-- Compartments -->
                            <div v-if="compartments.length > 0">
                                <p class="text-xs font-semibold text-slate-700">Security Compartments</p>
                                <div class="mt-1.5 flex flex-wrap gap-2">
                                    <label
                                        v-for="comp in compartments"
                                        :key="comp.id"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1 text-xs text-slate-700 cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="createForm.compartment_ids.includes(comp.id)"
                                            @change="toggleStringItem(createForm.compartment_ids, comp.id)"
                                            class="rounded border-slate-300"
                                        />
                                        <span class="font-mono font-semibold">{{ comp.code }}</span>
                                        <span class="text-slate-500">{{ comp.name }}</span>
                                    </label>
                                </div>
                            </div>

                            <label class="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                                <input v-model="createForm.mfa_enforced" type="checkbox" class="rounded border-slate-300" />
                                Enforce authenticator MFA setup on next login
                            </label>

                            <button
                                type="submit"
                                :disabled="createForm.processing"
                                class="flex w-full items-center justify-center gap-2 rounded-lg bg-[#0E7C66] px-4 py-2.5 text-sm font-semibold text-white hover:opacity-95 disabled:opacity-50"
                            >
                                <UserPlus class="h-4 w-4" />
                                Create User Account
                            </button>
                        </form>
                    </section>
                </div>

                <!-- Right Column: Directory & Inline Role/Permission Editor -->
                <div class="space-y-6 xl:col-span-7">
                    <!-- Edit Drawer when a user is selected -->
                    <section
                        v-if="editingUser"
                        class="rounded-[14px] border-2 border-[#0E7C66] bg-white p-5 shadow-md space-y-4"
                    >
                        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                            <div class="flex items-center gap-2">
                                <UserCog class="h-4 w-4 text-[#0E7C66]" />
                                <h2 class="text-sm font-bold text-slate-900">
                                    Edit Access — {{ editingUser.name }} ({{ editingUser.email }})
                                </h2>
                            </div>
                            <button
                                type="button"
                                @click="editingUser = null"
                                class="rounded-md p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800"
                            >
                                <X class="h-4 w-4" />
                            </button>
                        </div>

                        <form @submit.prevent="saveUserAccess" class="space-y-4">
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700">Department</label>
                                    <select
                                        v-model="editForm.org_unit_id"
                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                    >
                                        <option v-for="u in orgUnits" :key="u.id" :value="u.id">
                                            {{ u.code }} — {{ u.name }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700">Clearance Level</label>
                                    <select
                                        v-model="editForm.clearance_level_id"
                                        required
                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                    >
                                        <option v-for="lvl in clearanceLevels" :key="lvl.id" :value="lvl.id">
                                            L{{ lvl.rank }} — {{ lvl.name }}
                                        </option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700">Account Status</label>
                                    <select
                                        v-model="editForm.status"
                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                    >
                                        <option value="active">Active</option>
                                        <option value="suspended">Suspended</option>
                                    </select>
                                </div>
                            </div>

                            <div>
                                <p class="text-xs font-semibold text-slate-700">Assigned Roles</p>
                                <div class="mt-2 flex flex-wrap gap-2">
                                    <label
                                        v-for="role in roles"
                                        :key="role"
                                        class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 px-3 py-1.5 text-xs font-semibold text-slate-800 cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="editForm.roles.includes(role)"
                                            @change="toggleStringItem(editForm.roles, role)"
                                            class="rounded border-slate-300"
                                        />
                                        {{ role }}
                                    </label>
                                </div>
                            </div>

                            <div>
                                <div class="flex items-center justify-between">
                                    <p class="text-xs font-semibold text-slate-700">Direct Granular Permissions</p>
                                    <div class="flex gap-2 text-[11px]">
                                        <button
                                            type="button"
                                            @click="editForm.permissions = [...permissionsCatalog]"
                                            class="font-semibold text-[#0E7C66] hover:underline"
                                        >
                                            Select all
                                        </button>
                                        <button
                                            type="button"
                                            @click="editForm.permissions = []"
                                            class="font-semibold text-slate-500 hover:underline"
                                        >
                                            Clear
                                        </button>
                                    </div>
                                </div>
                                <div class="mt-2 grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                                    <label
                                        v-for="perm in permissionsCatalog"
                                        :key="perm"
                                        class="flex items-center gap-1.5 rounded border border-slate-200 px-2 py-1 text-[11px] font-mono text-slate-700 cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="editForm.permissions.includes(perm)"
                                            @change="toggleStringItem(editForm.permissions, perm)"
                                            class="rounded border-slate-300"
                                        />
                                        <span class="truncate">{{ perm }}</span>
                                    </label>
                                </div>
                            </div>

                            <div v-if="compartments.length > 0">
                                <p class="text-xs font-semibold text-slate-700">Compartments</p>
                                <div class="mt-1.5 flex flex-wrap gap-2">
                                    <label
                                        v-for="comp in compartments"
                                        :key="comp.id"
                                        class="inline-flex items-center gap-1.5 rounded border border-slate-200 px-2.5 py-1 text-xs text-slate-700 cursor-pointer"
                                    >
                                        <input
                                            type="checkbox"
                                            :checked="editForm.compartment_ids.includes(comp.id)"
                                            @change="toggleStringItem(editForm.compartment_ids, comp.id)"
                                            class="rounded border-slate-300"
                                        />
                                        <span class="font-mono font-semibold">{{ comp.code }}</span>
                                    </label>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 items-center">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-700">Reset Password (optional)</label>
                                    <input
                                        v-model="editForm.password"
                                        type="password"
                                        minlength="8"
                                        placeholder="Leave blank to keep existing"
                                        class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-1.5 text-sm"
                                    />
                                </div>
                                <label class="flex items-center gap-2 text-xs text-slate-700 mt-4 cursor-pointer">
                                    <input v-model="editForm.mfa_enforced" type="checkbox" class="rounded border-slate-300" />
                                    Require MFA verification
                                </label>
                            </div>

                            <div class="flex justify-end gap-2">
                                <button
                                    type="button"
                                    @click="editingUser = null"
                                    class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-50"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    :disabled="editForm.processing"
                                    class="rounded-lg bg-[#0E7C66] px-4 py-2 text-xs font-semibold text-white hover:opacity-95 disabled:opacity-50"
                                >
                                    Save Roles &amp; Permissions
                                </button>
                            </div>
                        </form>
                    </section>

                    <!-- Users Directory -->
                    <section class="rounded-[14px] border border-slate-200 bg-white p-5 shadow-xs space-y-4">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                            <div class="flex items-center gap-2">
                                <Users class="h-4 w-4 text-[#0E7C66]" />
                                <h2 class="text-sm font-bold text-slate-900">Organisation Users &amp; Access Matrix</h2>
                            </div>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-slate-200 text-left text-xs">
                                <thead class="bg-slate-50 font-semibold uppercase tracking-wider text-slate-500">
                                    <tr>
                                        <th class="px-3 py-2.5">User</th>
                                        <th class="px-3 py-2.5">Clearance &amp; Unit</th>
                                        <th class="px-3 py-2.5">Roles &amp; Permissions</th>
                                        <th class="px-3 py-2.5">Status</th>
                                        <th class="px-3 py-2.5 text-right">Action</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100">
                                    <tr v-for="u in users" :key="u.id" class="hover:bg-slate-50/70">
                                        <td class="px-3 py-3 align-top">
                                            <p class="font-bold text-slate-900">
                                                {{ u.rank_title ? `${u.rank_title} ` : '' }}{{ u.name }}
                                            </p>
                                            <p class="text-[11px] text-slate-500">{{ u.email }}</p>
                                        </td>
                                        <td class="px-3 py-3 align-top space-y-1">
                                            <ClassificationBadge
                                                :name="u.clearance_name ?? 'Level'"
                                                :color-hex="u.clearance_color ?? '#475569'"
                                                :rank="u.clearance_rank"
                                            />
                                            <p class="text-[11px] text-slate-600">{{ u.org_unit_name ?? 'Unassigned' }}</p>
                                            <div v-if="u.compartment_codes.length > 0" class="flex flex-wrap gap-1">
                                                <span
                                                    v-for="c in u.compartment_codes"
                                                    :key="c"
                                                    class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-[10px] text-slate-700"
                                                >
                                                    {{ c }}
                                                </span>
                                            </div>
                                        </td>
                                        <td class="px-3 py-3 align-top space-y-1.5">
                                            <div class="flex flex-wrap gap-1">
                                                <span
                                                    v-for="r in u.roles"
                                                    :key="r"
                                                    class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-[#0E7C66] ring-1 ring-emerald-200"
                                                >
                                                    <ShieldCheck class="h-3 w-3" /> {{ r }}
                                                </span>
                                            </div>
                                            <p class="text-[11px] text-slate-500 font-mono">
                                                {{ u.permissions.length }} effective permissions
                                            </p>
                                            <span
                                                v-if="u.mfa_enforced"
                                                class="inline-flex items-center gap-1 text-[10px] font-semibold text-amber-700"
                                            >
                                                <KeyRound class="h-3 w-3" /> MFA required
                                            </span>
                                        </td>
                                        <td class="px-3 py-3 align-top">
                                            <span
                                                class="inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize"
                                                :class="
                                                    u.status === 'active'
                                                        ? 'bg-emerald-100 text-emerald-800'
                                                        : 'bg-red-100 text-red-800'
                                                "
                                            >
                                                {{ u.status }}
                                            </span>
                                        </td>
                                        <td class="px-3 py-3 align-top text-right">
                                            <button
                                                type="button"
                                                @click="startEditUser(u)"
                                                class="rounded-lg border border-slate-300 bg-white px-2.5 py-1.5 text-[11px] font-semibold text-slate-800 hover:bg-slate-50"
                                            >
                                                Edit Roles &amp; Access
                                            </button>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
