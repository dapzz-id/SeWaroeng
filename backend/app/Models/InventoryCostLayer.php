<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable historical cost record created whenever stock increases.
 *
 * Model A (Historical Cost Provenance Only):
 *   - Sole authoritative costing method in Phase 1 is Moving Weighted Average (MWA).
 *   - InventoryCostLayer provides append-only immutable historical provenance of inbound receipts.
 *   - Created by: purchase_receipt, transfer_in, adjustment_in, opening_balance, stock_opname.
 *   - Stores original inbound quantity and cost basis; does NOT track live lot depletion.
 *   - Authoritative COGS and outbound valuation do NOT use FIFO layer ordering; they use location MWA.
 *   - unit_cost_minor: bigint IDR minor units (Rp 15,000 = 15000).
 *   - total_cost_minor = ROUND(quantity × unit_cost_minor) — integer, no float.
 */
class InventoryCostLayer extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const UPDATED_AT = null;

    public const INBOUND_SOURCE_TYPES = [
        'purchase_receipt',
        'transfer_in',
        'adjustment_in',
        'opening_balance',
        'stock_opname',
    ];

    protected $table = 'inventory_cost_layers';

    protected $fillable = [
        'tenant_id',
        'inventory_item_id',
        'location_id',
        'source_movement_id',
        'source_type',
        'source_reference',
        'quantity',
        'unit_cost_minor',
        'total_cost_minor',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_cost_minor' => 'integer',
            'total_cost_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new DomainException('Inventory cost layers are strictly immutable after creation.');
        });

        static::deleting(function () {
            throw new DomainException('Inventory cost layers cannot be deleted.');
        });
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class, 'source_movement_id');
    }
}
