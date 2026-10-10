<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Sale extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_OPEN = 'open';

    public const STATUS_FINALIZED = 'finalized';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'sales';

    protected $fillable = [
        'id',
        'tenant_id',
        'outlet_id',
        'reference_number',
        'status',
        'opened_at',
        'finalized_at',
        'cancelled_at',
        'cashier_user_id',
        'customer_id',
        'subtotal_minor',
        'discount_minor',
        'tax_minor',
        'total_minor',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'finalized_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    public function isFinalized(): bool
    {
        return $this->status === self::STATUS_FINALIZED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_user_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(Receipt::class, 'sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class, 'sale_id');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(SaleItemModifier::class, 'sale_id');
    }

    public function consumptions(): HasMany
    {
        return $this->hasMany(SaleConsumption::class, 'sale_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'sale_id');
    }

    public function voucher(): HasOne
    {
        return $this->hasOne(LoyaltyVoucher::class, 'used_sale_id');
    }

    public function getPaidAmountMinor(): int
    {
        return (int) $this->payments()
            ->whereIn('status', [Payment::STATUS_SUCCEEDED, Payment::STATUS_PARTIALLY_REFUNDED, Payment::STATUS_REFUNDED])
            ->sum('amount_minor');
    }

    public function getRefundedAmountMinor(): int
    {
        return (int) PaymentRefund::query()
            ->where('tenant_id', $this->tenant_id)
            ->whereIn('payment_id', $this->payments()->select('id'))
            ->where('status', PaymentRefund::STATUS_COMPLETED)
            ->sum('amount_minor');
    }

    public function getOutstandingAmountMinor(): int
    {
        $netPaid = $this->getPaidAmountMinor() - $this->getRefundedAmountMinor();

        return max(0, (int) $this->total_minor - $netPaid);
    }
}
