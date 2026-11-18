<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Enums\BetterDocRole;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionCatalog
{
    /**
     * Authoritative list of assignable granular permissions across BetterDoc DMS.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            'manage-branding',
            'manage-users',
            'documents.view',
            'documents.create',
            'documents.edit',
            'documents.share',
            'documents.approve',
            'documents.sign',
            'documents.delete',
            'workflows.manage',
            'finance.manage',
            'contracts.manage',
            'hr.manage',
            'audit.view',
            'compliance.export',
        ];
    }

    /**
     * Ensure all BetterDoc roles & permissions exist for a tenant and assign full permissions to SystemAdmin.
     */
    public static function syncTenantRolesAndPermissions(string $tenantId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

        $permissions = [];
        foreach (self::all() as $name) {
            $permissions[] = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);
        }

        foreach (BetterDocRole::values() as $roleName) {
            /** @var Role $role */
            $role = Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
                'tenant_id' => $tenantId,
            ]);

            if ($roleName === BetterDocRole::SystemAdmin->value) {
                $role->syncPermissions($permissions);
            }
        }
    }
}
