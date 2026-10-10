<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryItemLocation extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'inventory_item_locations';

    protected $fillable = [
        'tenant_id',
        'inventory_item_id',
        'location_id',
        'on_hand',
        'reserved',
        'reorder_level',
    ];

    protected function casts(): array
    {
        return [
            'on_hand' => 'decimal:4',
            'reserved' => 'decimal:4',
            'reorder_level' => 'decimal:4',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(InventoryLocation::class, 'location_id');
    }

    public function getAvailableAttribute(): string
    {
        return bcsub((string) $this->on_hand, (string) $this->reserved, 4);
    }

    public function isLowStock(): bool
    {
        return bccomp($this->available, (string) $this->reorder_level, 4) <= 0;
    }
}
