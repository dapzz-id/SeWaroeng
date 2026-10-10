<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerLoyaltyAccount extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CLOSED = 'closed';

    protected $table = 'customer_loyalty_accounts';

    protected $fillable = [
        'tenant_id',
        'customer_id',
        'loyalty_program_id',
        'tier_id',
        'current_balance',
        'lifetime_earned',
        'lifetime_redeemed',
        'status',
        'joined_at',
    ];

    protected function casts(): array
    {
        return [
            'current_balance' => 'integer',
            'lifetime_earned' => 'integer',
            'lifetime_redeemed' => 'integer',
            'joined_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(LoyaltyTier::class, 'tier_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(LoyaltyPointLedger::class, 'loyalty_account_id')->orderBy('created_at', 'desc');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(LoyaltyVoucher::class, 'customer_id', 'customer_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
