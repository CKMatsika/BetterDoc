<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Models\BreakGlassSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

class DbSecurityContext
{
    /** @var list<array<string, string>> */
    private array $contextStack = [];

    /** @var array<string, string>|null */
    private ?array $currentVars = null;

    public function applyForUser(?User $user, ?string $connectionName = null): void
    {
        $conn = DB::connection($connectionName);

        if ($user === null) {
            $this->clear($connectionName);

            return;
        }

        app(PermissionRegistrar::class)->setPermissionsTeamId($user->tenant_id);

        if ($conn->getDriverName() !== 'pgsql') {
            return;
        }

        $compartmentIds = implode(',', $user->activeCompartmentIds());
        $ancestorUnitIds = $user->orgUnit !== null
            ? implode(',', $user->orgUnit->ancestorAndSelfIds())
            : $user->org_unit_id;

        $roleList = [];
        foreach ($user->roles()->pluck('roles.id')->all() as $rid) {
            if (is_string($rid) || is_int($rid)) {
                $roleList[] = (string) $rid;
            }
        }
        $roleIds = implode(',', $roleList);

        // Set tenant/user context first so RLS-protected break_glass_sessions query resolves for this tenant
        $baseVars = [
            'sentinel.tenant_id' => $user->tenant_id,
            'sentinel.user_id' => $user->id,
            'sentinel.clearance_rank' => (string) $user->clearance_rank,
            'sentinel.compartment_ids' => $compartmentIds,
            'sentinel.org_unit_path' => $user->orgUnit !== null ? $user->orgUnit->path : '',
            'sentinel.ancestor_unit_ids' => $ancestorUnitIds,
            'sentinel.role_ids' => $roleIds,
            'sentinel.break_glass_doc_ids' => '',
        ];

        $this->setVariables($baseVars, $connectionName);

        $bgList = [];
        $bgRows = BreakGlassSession::query()
            ->where('tenant_id', $user->tenant_id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->pluck('document_id')
            ->all();

        foreach ($bgRows as $docId) {
            if (is_string($docId) || is_int($docId)) {
                $bgList[] = (string) $docId;
            }
        }
        $breakGlassIds = implode(',', $bgList);

        if ($breakGlassIds !== '') {
            $baseVars['sentinel.break_glass_doc_ids'] = $breakGlassIds;
            $this->setVariables($baseVars, $connectionName);
        }
    }

    public function clear(?string $connectionName = null): void
    {
        $this->contextStack = [];
        $this->currentVars = null;

        $conn = DB::connection($connectionName);
        if ($conn->getDriverName() !== 'pgsql') {
            return;
        }

        $this->writeVarsToConnection([
            'sentinel.tenant_id' => '',
            'sentinel.user_id' => '',
            'sentinel.clearance_rank' => '-1',
            'sentinel.compartment_ids' => '',
            'sentinel.org_unit_path' => '',
            'sentinel.ancestor_unit_ids' => '',
            'sentinel.role_ids' => '',
            'sentinel.break_glass_doc_ids' => '',
        ], $connectionName);
    }

    /**
     * Execute a closure under a user's PostgreSQL RLS context and restore the prior context cleanly.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runAs(User $user, callable $callback, ?string $connectionName = null): mixed
    {
        $previous = $this->currentVars;
        if ($previous !== null) {
            $this->contextStack[] = $previous;
        }

        $this->applyForUser($user, $connectionName);

        try {
            return $callback();
        } finally {
            try {
                if ($this->contextStack !== []) {
                    $restored = array_pop($this->contextStack);
                    $this->setVariables($restored, $connectionName);
                } else {
                    $this->clear($connectionName);
                }
            } catch (\Throwable) {
                // Preserve original exception when the active PostgreSQL transaction is already aborted.
            }
        }
    }

    /**
     * Execute a tenant-scoped closure (e.g. CA Root / CRL / OCSP lookup) on the default connection.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runForTenant(string $tenantId, callable $callback, ?string $connectionName = null): mixed
    {
        $conn = DB::connection($connectionName);
        if ($conn->getDriverName() !== 'pgsql') {
            return $callback();
        }

        $previous = $this->currentVars;
        if ($previous !== null) {
            $this->contextStack[] = $previous;
        }

        $this->setVariables(['sentinel.tenant_id' => $tenantId], $connectionName);

        try {
            return $callback();
        } finally {
            try {
                if ($this->contextStack !== []) {
                    $restored = array_pop($this->contextStack);
                    $this->setVariables($restored, $connectionName);
                } else {
                    $this->clear($connectionName);
                }
            } catch (\Throwable) {
                // Preserve original exception when transaction is aborted.
            }
        }
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function setVariables(array $vars, ?string $connectionName = null): void
    {
        $this->currentVars = $vars;
        $this->writeVarsToConnection($vars, $connectionName);
    }

    /**
     * @param  array<string, string>  $vars
     */
    private function writeVarsToConnection(array $vars, ?string $connectionName = null): void
    {
        $conn = DB::connection($connectionName);

        foreach ($vars as $key => $value) {
            $conn->select('SELECT set_config(?, ?, false)', [$key, $value]);
        }
    }
}
