<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use App\Exceptions\PriceNotConfiguredException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariant extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'product_id',
        'name',
        'sku',
        'status',
        'is_default',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'sort_order' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class, 'variant_id');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(Price::class, 'variant_id');
    }

    /**
     * Resolves the active effective price for this variant.
     * Precedence:
     * 1. Active outlet-specific price (effective now)
     * 2. Active tenant-wide default price (effective now)
     */
    public function resolvePrice(?string $outletId = null): ?Price
    {
        $now = now();

        if ($outletId) {
            $outletPrice = $this->prices()
                ->where('outlet_id', $outletId)
                ->where('is_active', true)
                ->where('effective_from', '<=', $now)
                ->where(function ($q) use ($now) {
                    $q->whereNull('effective_until')
                        ->orWhere('effective_until', '>=', $now);
                })
                ->orderByDesc('effective_from')
                ->first();

            if ($outletPrice) {
                return $outletPrice;
            }
        }

        return $this->prices()
            ->whereNull('outlet_id')
            ->where('is_active', true)
            ->where('effective_from', '<=', $now)
            ->where(function ($q) use ($now) {
                $q->whereNull('effective_until')
                    ->orWhere('effective_until', '>=', $now);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    /**
     * Resolves the active effective price for this variant or throws PriceNotConfiguredException.
     * Prevents selling an unpriced item or silently falling back to 0.
     *
     * @throws PriceNotConfiguredException
     */
    public function getEffectivePrice(?string $outletId = null): Price
    {
        $price = $this->resolvePrice($outletId);

        if (! $price) {
            throw new PriceNotConfiguredException("No active price configured for variant [{$this->id}].");
        }

        return $price;
    }
}
