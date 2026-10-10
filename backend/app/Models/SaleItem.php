<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleItem extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'sale_items';

    protected $fillable = [
        'id',
        'tenant_id',
        'sale_id',
        'product_variant_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'quantity',
        'unit_price_minor',
        'subtotal_minor',
        'discount_minor',
        'total_minor',
        'price_id',
        'recipe_version_id',
        'recipe_snapshot',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'recipe_snapshot' => 'array',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(SaleItemModifier::class, 'sale_item_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(SaleConsumption::class, 'sale_item_id');
    }
}
