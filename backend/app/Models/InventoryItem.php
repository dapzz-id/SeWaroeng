<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const PRODUCTION_MODE_STOCKED = 'stocked';

    public const PRODUCTION_MODE_MAKE_TO_ORDER = 'make_to_order';

    protected $fillable = [
        'tenant_id',
        'product_variant_id',
        'sku',
        'name',
        'item_type',
        'production_mode',
        'base_unit',
        'status',
        'allow_negative_stock',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'allow_negative_stock' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function itemLocations(): HasMany
    {
        return $this->hasMany(InventoryItemLocation::class, 'inventory_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class, 'inventory_item_id');
    }

    public function getLocationBalance(string $locationId): ?InventoryItemLocation
    {
        return $this->itemLocations()->where('location_id', $locationId)->first();
    }

    public function isStocked(): bool
    {
        return ($this->production_mode ?? self::PRODUCTION_MODE_STOCKED) === self::PRODUCTION_MODE_STOCKED;
    }

    public function isMakeToOrder(): bool
    {
        return ($this->production_mode ?? self::PRODUCTION_MODE_STOCKED) === self::PRODUCTION_MODE_MAKE_TO_ORDER;
    }
}
