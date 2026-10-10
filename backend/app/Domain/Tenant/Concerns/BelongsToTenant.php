<?php

namespace App\Domain\Tenant\Concerns;

use App\Domain\Tenant\Scopes\TenantScope;
use App\Domain\Tenant\TenantManager;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToTenant
{
    /**
     * Boot the BelongsToTenant trait for a model.
     */
    protected static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model) {
            if (empty($model->tenant_id)) {
                $tenantManager = app(TenantManager::class);
                if ($tenantManager->hasContext()) {
                    $model->tenant_id = $tenantManager->currentTenantId();
                }
            }
        });
    }

    /**
     * Relationship to the owning Tenant.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }
}
