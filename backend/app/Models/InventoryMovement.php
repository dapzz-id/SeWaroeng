<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const UPDATED_AT = null;

    public const PHYSICAL_MOVEMENT_TYPES = [
        'opening_balance',
        'adjustment_in',
        'adjustment_out',
        'stock_opname',
        'transfer_in',
        'transfer_out',
        'waste',
        'expired',
        'purchase_receipt',
    ];

    public const RESERVATION_MOVEMENT_TYPES = [
        'reserved',
        'released',
    ];

    protected $fillable = [
        'tenant_id',
        'movement_number',
        'inventory_item_id',
        'location_id',
        'movement_type',
        'quantity_delta',
        'unit',
        'before_on_hand',
        'after_on_hand',
        'reference_type',
        'reference_id',
        'reason',
        'note',
        'occurred_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity_delta' => 'decimal:4',
            'before_on_hand' => 'decimal:4',
            'after_on_hand' => 'decimal:4',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function ($movement) {
            throw new DomainException('Posted inventory movements are immutable.');
        });

        static::deleting(function ($movement) {
            throw new DomainException('Posted inventory movements cannot be deleted.');
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
