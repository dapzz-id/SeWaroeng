<?php

namespace App\Domain\Tenant;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Throwable;

class TenantManager
{
    private ?TenantContext $currentContext = null;

    public function setContext(TenantContext $context): void
    {
        $this->currentContext = $context;
        app()->instance(TenantContext::class, $context);

        // Bind PostgreSQL RLS context safely
        $tenantId = $context->id();
        try {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, false)", [$tenantId]);
        } catch (Throwable) {
            // Connection may not be established yet or driver is not postgresql in mock tests
        }
    }

    public function getContext(): ?TenantContext
    {
        return $this->currentContext;
    }

    public function hasContext(): bool
    {
        return $this->currentContext !== null;
    }

    public function currentTenantId(): ?string
    {
        return $this->currentContext?->id();
    }

    public function clearContext(): void
    {
        $this->currentContext = null;
        app()->forgetInstance(TenantContext::class);

        try {
            DB::statement("SELECT set_config('app.current_tenant_id', '', false)");
        } catch (Throwable) {
            // Ignored if DB is disconnected
        }
    }

    /**
     * Executes a callback within a strict, transaction-scoped tenant context (SET LOCAL).
     * Synchronizes both in-memory TenantContext (Layer 1) and PostgreSQL RLS session (Layer 2).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runForTenant(string|Tenant $tenant, callable $callback): mixed
    {
        $tenantModel = $tenant instanceof Tenant
            ? $tenant
            : Tenant::withoutGlobalScopes()->findOrFail($tenant);

        $tenantId = (string) $tenantModel->id;
        $previousContext = $this->currentContext;

        try {
            // Establish in-memory TenantContext
            $membership = $tenantModel->memberships()
                ->with('role.permissions')
                ->first();

            if ($membership) {
                $this->setContext(new TenantContext($tenantModel, $membership));
            }

            return DB::transaction(function () use ($tenantId, $callback) {
                DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [$tenantId]);

                return $callback();
            });
        } finally {
            if ($previousContext) {
                $this->setContext($previousContext);
            } else {
                $this->clearContext();
            }
        }
    }
}
