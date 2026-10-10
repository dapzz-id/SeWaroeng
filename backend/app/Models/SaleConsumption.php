<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleConsumption extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'sale_consumptions';

    protected $fillable = [
        'tenant_id',
        'sale_id',
        'sale_item_id',
        'inventory_item_id',
        'location_id',
        'consumed_quantity',
        'unit_cost_minor',
        'total_cost_minor',
        'inventory_movement_id',
        'recipe_component_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'consumed_quantity' => 'decimal:4',
            'unit_cost_minor' => 'integer',
            'total_cost_minor' => 'integer',
            'recipe_component_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException('Sale consumptions are strictly immutable historical records.');
        });

        static::deleting(function () {
            throw new DomainException('Sale consumptions cannot be deleted.');
        });
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'inventory_movement_id');
    }
}
