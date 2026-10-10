<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SaleItemModifier extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const UPDATED_AT = null;

    protected $table = 'sale_item_modifiers';

    protected $fillable = [
        'tenant_id',
        'sale_id',
        'sale_item_id',
        'modifier_id',
        'modifier_name_snapshot',
        'modifier_group_name_snapshot',
        'quantity',
        'unit_price_minor',
        'subtotal_minor',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class, 'sale_item_id');
    }

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(Modifier::class, 'modifier_id');
    }

    public function getUnitPriceAdjustmentMinorAttribute(): int
    {
        return (int) $this->unit_price_minor;
    }
}
