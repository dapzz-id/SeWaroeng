<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'order_items';

    protected $fillable = [
        'id',
        'tenant_id',
        'order_id',
        'product_variant_id',
        'product_name_snapshot',
        'variant_name_snapshot',
        'quantity',
        'unit_price_minor',
        'subtotal_minor',
        'notes',
        'selected_modifiers',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price_minor' => 'integer',
            'subtotal_minor' => 'integer',
            'selected_modifiers' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }
}
