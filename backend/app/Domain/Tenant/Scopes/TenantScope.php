<?php

namespace App\Domain\Tenant\Scopes;

use App\Domain\Tenant\TenantManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class TenantScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantManager = app(TenantManager::class);

        if ($tenantManager->hasContext()) {
            $builder->where($model->qualifyColumn('tenant_id'), '=', $tenantManager->currentTenantId());
        } else {
            // Fail-closed at the application layer if context has not been established
            // Allows bypassing explicitly with withoutGlobalScope(TenantScope::class)
            $builder->whereRaw('1 = 0');
        }
    }
}
