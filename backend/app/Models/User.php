<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUuidV7, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone_number',
        'password',
        'pin_hash',
        'is_active',
        'is_superadmin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pin_hash',
    ];

    protected $attributes = [
        'is_active' => true,
        'is_superadmin' => false,
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_superadmin' => 'boolean',
        ];
    }

    public function isSuperadmin(): bool
    {
        return (bool) $this->is_superadmin;
    }

    public function hasPlatformPermission(string $permission): bool
    {
        if (! $this->isSuperadmin()) {
            return false;
        }

        $platformPermissions = [
            'platform.payment_provider.view',
            'platform.payment_provider.manage',
            'platform.payment_provider.verify',
            'platform.payment_provider.enable',
            'platform.payment_provider.disable',
        ];

        return in_array($permission, $platformPermissions, true);
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UserTenantMembership::class, 'user_id');
    }

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'user_tenant_memberships', 'user_id', 'tenant_id')
            ->withPivot(['id', 'role_id', 'status', 'outlet_access', 'assigned_outlet_ids'])
            ->withTimestamps();
    }

    public function ownedTenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'owner_user_id');
    }
}
