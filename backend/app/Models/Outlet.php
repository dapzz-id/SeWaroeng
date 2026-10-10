<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Exceptions\InventoryLocationOutletMismatchException;
use App\Exceptions\NoPosInventoryLocationException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Outlet extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'default_pos_location_id',
        'name',
        'code',
        'address',
        'address_line',
        'city',
        'province',
        'postal_code',
        'phone_number',
        'latitude',
        'longitude',
        'timezone',
        'operating_hours',
        'settings',
        'status',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'operating_hours' => 'array',
            'settings' => 'array',
        ];
    }

    /**
     * Resolves the effective timezone for this outlet, falling back to business timezone or system default.
     */
    public function effectiveTimezone(): string
    {
        return $this->timezone ?: ($this->tenant?->timezone ?? 'Asia/Jakarta');
    }

    public function defaultPosLocation(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'default_pos_location_id');
    }

    /**
     * Deterministically resolves the server-authoritative inventory location for POS transactions.
     * Enforces that the location belongs strictly to this outlet and tenant.
     * Never falls back to another outlet or global location.
     */
    public function resolvePosInventoryLocation(?string $requestedLocationId = null): InventoryLocation
    {
        if ($requestedLocationId !== null) {
            $location = InventoryLocation::where('tenant_id', $this->tenant_id)->find($requestedLocationId);

            if (! $location) {
                throw new NoPosInventoryLocationException("Requested inventory location [{$requestedLocationId}] not found in this tenant.");
            }

            if ($location->outlet_id !== $this->id) {
                throw new InventoryLocationOutletMismatchException(
                    "Inventory location [{$location->id}] belongs to outlet [{$location->outlet_id}], which does not match sale outlet [{$this->id}]."
                );
            }

            if (! $location->is_active) {
                throw new NoPosInventoryLocationException("Requested inventory location [{$location->id}] is inactive.");
            }

            return $location;
        }

        // 1. Check explicit default_pos_location_id
        if ($this->default_pos_location_id) {
            $defaultLoc = InventoryLocation::where('tenant_id', $this->tenant_id)
                ->where('outlet_id', $this->id)
                ->where('id', $this->default_pos_location_id)
                ->where('is_active', true)
                ->first();

            if ($defaultLoc) {
                return $defaultLoc;
            }
        }

        // 2. Fallback to primary active location for this outlet (type outlet or kitchen first)
        $primaryLoc = InventoryLocation::where('tenant_id', $this->tenant_id)
            ->where('outlet_id', $this->id)
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN type IN ('outlet', 'kitchen') THEN 0 ELSE 1 END")
            ->orderBy('created_at')
            ->first();

        if ($primaryLoc) {
            return $primaryLoc;
        }

        throw new NoPosInventoryLocationException(
            "No active inventory location configured for outlet [{$this->name}] ({$this->id}). POS cannot finalize transactions."
        );
    }

    public function permittedMemberships(): BelongsToMany
    {
        return $this->belongsToMany(UserTenantMembership::class, 'outlet_user_access', 'outlet_id', 'membership_id')
            ->using(OutletUserAccess::class)
            ->withTimestamps();
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class)->orderBy('opened_at', 'desc');
    }

    public function activeShift(): HasOne
    {
        return $this->hasOne(Shift::class)->where('status', Shift::STATUS_OPEN);
    }
}
