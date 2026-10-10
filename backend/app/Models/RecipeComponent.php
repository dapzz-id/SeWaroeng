<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecipeComponent extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const ALLOWED_TYPES = ['raw_material', 'packaging', 'semi_finished', 'finished_good'];

    public const CONSUMPTION_MODE_STOCKED = 'stocked';

    public const CONSUMPTION_MODE_MAKE_TO_ORDER = 'make_to_order';

    protected $fillable = [
        'tenant_id',
        'recipe_version_id',
        'inventory_item_id',
        'quantity',
        'unit',
        'component_type',
        'consumption_mode',
        'waste_factor',
        'sequence',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'waste_factor' => 'decimal:4',
            'sequence' => 'integer',
        ];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(RecipeVersion::class, 'recipe_version_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    /**
     * Gross quantity including waste: quantity * (1 + waste_factor).
     * Uses bcmath for precision. Scale: 6.
     */
    public function grossQuantity(): string
    {
        return bcmul((string) $this->quantity, bcadd('1', (string) $this->waste_factor, 6), 6);
    }

    /**
     * Resolves the effective physical consumption mode for this recipe component.
     * Precedence: component explicit consumption_mode -> inventory item production_mode -> default 'stocked'.
     */
    public function effectiveConsumptionMode(): string
    {
        if ($this->consumption_mode) {
            return $this->consumption_mode;
        }

        if ($this->inventoryItem && $this->inventoryItem->production_mode) {
            return $this->inventoryItem->production_mode;
        }

        return self::CONSUMPTION_MODE_STOCKED;
    }
}
