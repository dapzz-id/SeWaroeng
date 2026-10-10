<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoyaltyPointLedger extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const UPDATED_AT = null; // Ledgers have no updated_at column

    public const TYPE_PURCHASE = 'purchase';

    public const TYPE_REDEMPTION = 'redemption';

    public const TYPE_CAMPAIGN = 'campaign';

    public const TYPE_REFERRAL = 'referral';

    public const TYPE_BIRTHDAY = 'birthday';

    public const TYPE_ADJUSTMENT = 'adjustment';

    public const TYPE_EXPIRATION = 'expiration';

    public const TYPE_REVERSAL = 'reversal';

    public const VALID_TYPES = [
        self::TYPE_PURCHASE,
        self::TYPE_REDEMPTION,
        self::TYPE_CAMPAIGN,
        self::TYPE_REFERRAL,
        self::TYPE_BIRTHDAY,
        self::TYPE_ADJUSTMENT,
        self::TYPE_EXPIRATION,
        self::TYPE_REVERSAL,
    ];

    protected $table = 'loyalty_point_ledgers';

    protected $fillable = [
        'tenant_id',
        'loyalty_program_id',
        'loyalty_account_id',
        'customer_id',
        'type',
        'points',
        'balance_after',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'notes',
        'metadata',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'integer',
            'balance_after' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * Boot the model with immutable enforcement.
     */
    protected static function booted(): void
    {
        static::updating(function () {
            throw new \RuntimeException('Loyalty point ledger is immutable and cannot be updated.');
        });

        static::deleting(function () {
            throw new \RuntimeException('Loyalty point ledger is immutable and cannot be deleted.');
        });
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CustomerLoyaltyAccount::class, 'loyalty_account_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }
}
