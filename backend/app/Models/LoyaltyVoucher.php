<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyVoucher extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_USED = 'used';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'loyalty_vouchers';

    protected $fillable = [
        'tenant_id',
        'loyalty_program_id',
        'customer_id',
        'reward_id',
        'code',
        'status',
        'discount_amount_minor',
        'discount_percent',
        'min_purchase_minor',
        'issued_at',
        'expires_at',
        'used_at',
        'used_sale_id',
    ];

    protected function casts(): array
    {
        return [
            'discount_amount_minor' => 'integer',
            'discount_percent' => 'integer',
            'min_purchase_minor' => 'integer',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function reward(): BelongsTo
    {
        return $this->belongsTo(LoyaltyReward::class, 'reward_id');
    }

    public function usedSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'used_sale_id');
    }

    public function isValidForUse(): bool
    {
        if ($this->status !== self::STATUS_ACTIVE) {
            return false;
        }

        if (now()->gt($this->expires_at)) {
            return false;
        }

        return true;
    }

    public function isReservedForSale(string $saleId): bool
    {
        return $this->status === self::STATUS_RESERVED && $this->used_sale_id === $saleId;
    }

    /**
     * Calculate authoritative discount amount in minor units given a transaction subtotal.
     */
    public function calculateDiscount(int $subtotalMinor): int
    {
        if (now()->gt($this->expires_at)) {
            return 0;
        }

        if ($subtotalMinor < $this->min_purchase_minor) {
            return 0;
        }

        if ($this->discount_percent !== null && $this->discount_percent > 0) {
            $discount = (int) round(($subtotalMinor * $this->discount_percent) / 100);
            if ($this->discount_amount_minor > 0) {
                // If cap is set in discount_amount_minor
                $discount = min($discount, $this->discount_amount_minor);
            }

            return min($discount, $subtotalMinor);
        }

        return min($this->discount_amount_minor, $subtotalMinor);
    }
}
