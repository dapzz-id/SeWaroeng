<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tenant extends Model
{
    use HasFactory, HasUuidV7;

    protected $fillable = [
        'name',
        'slug',
        'code',
        'business_type',
        'phone',
        'email',
        'description',
        'address_line',
        'city',
        'province',
        'postal_code',
        'country_code',
        'latitude',
        'longitude',
        'currency',
        'timezone',
        'owner_user_id',
        'status',
        'is_active',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'settings' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function outlets(): HasMany
    {
        return $this->hasMany(Outlet::class, 'tenant_id');
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(UserTenantMembership::class, 'tenant_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_tenant_memberships', 'tenant_id', 'user_id')
            ->withPivot(['id', 'role_id', 'status', 'outlet_access', 'assigned_outlet_ids'])
            ->withTimestamps();
    }
}
