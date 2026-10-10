<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Materialized Moving Weighted Average (MWA) cost per (tenant, inventory_item, location).
 *
 * This is a read model / projection. The cost authority is InventoryCostLayer.
 * average_cost_minor is stored as bigint IDR minor units (rounded half-up).
 *
 * MWA formula applied during each inbound cost layer creation:
 *   new_avg = ROUND(
 *     (on_hand_before × old_avg + received_qty × received_cost)
 *     / (on_hand_before + received_qty)
 *   )
 *
 * Special cases:
 *   - on_hand_before <= 0 AND received_qty > 0: new_avg = received_cost
 *   - Negative inventory: average_cost preserved (not recalculated with negative denominator)
 */
class InventoryCostState extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const CREATED_AT = null;

    protected $table = 'inventory_cost_states';

    protected $fillable = [
        'tenant_id',
        'inventory_item_id',
        'location_id',
        'average_cost_minor',
        'last_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'average_cost_minor' => 'integer',
            'updated_at' => 'datetime',
        ];
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }
}
