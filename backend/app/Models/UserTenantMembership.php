<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class UserTenantMembership extends Model
{
    use HasFactory, HasUuidV7;

    protected $fillable = [
        'user_id',
        'tenant_id',
        'role_id',
        'status',
        'outlet_access',
        'assigned_outlet_ids',
        'custom_permissions',
    ];

    protected function casts(): array
    {
        return [
            'assigned_outlet_ids' => 'array',
            'custom_permissions' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function permittedOutlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'outlet_user_access', 'membership_id', 'outlet_id')
            ->using(OutletUserAccess::class)
            ->withTimestamps();
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function hasPermission(string $permissionSlug): bool
    {
        if ($this->role && $this->role->slug === 'owner') {
            return true;
        }

        // Custom permissions override
        if (! empty($this->custom_permissions) && array_key_exists($permissionSlug, $this->custom_permissions)) {
            return (bool) $this->custom_permissions[$permissionSlug];
        }

        // Check assigned role permissions
        if ($this->relationLoaded('role') && $this->role->relationLoaded('permissions')) {
            return $this->role->permissions->contains('slug', $permissionSlug);
        }

        return $this->role()->whereHas('permissions', function ($q) use ($permissionSlug) {
            $q->where('slug', $permissionSlug);
        })->exists();
    }
}
