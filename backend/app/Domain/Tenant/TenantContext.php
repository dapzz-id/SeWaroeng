<?php

namespace App\Domain\Tenant;

use App\Models\Tenant;
use App\Models\UserTenantMembership;

class TenantContext
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly UserTenantMembership $membership
    ) {}

    public function id(): string
    {
        return (string) $this->tenant->id;
    }

    public function tenant(): Tenant
    {
        return $this->tenant;
    }

    public function membership(): UserTenantMembership
    {
        return $this->membership;
    }

    public function role(): string
    {
        return (string) $this->membership->role->slug;
    }

    public function isOwner(): bool
    {
        return $this->role() === 'owner';
    }

    /**
     * Checks if the active user has access to the specified outlet within this tenant.
     */
    public function hasOutletAccess(string $outletId): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        if ($this->membership->outlet_access === 'all') {
            return true;
        }

        $assigned = $this->membership->assigned_outlet_ids ?? [];

        return in_array($outletId, $assigned, true);
    }

    /**
     * Checks if the active user possesses a specific permission in this tenant context.
     */
    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->isOwner()) {
            return true;
        }

        return $this->membership->hasPermission($permissionSlug);
    }
}
