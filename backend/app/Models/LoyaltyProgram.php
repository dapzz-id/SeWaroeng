<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyProgram extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const ROUNDING_FLOOR = 'floor';

    public const ROUNDING_ROUND = 'round';

    public const ROUNDING_CEIL = 'ceil';

    protected $table = 'loyalty_programs';

    protected $fillable = [
        'tenant_id',
        'name',
        'status',
        'points_per_currency',
        'earn_unit_amount_minor',
        'minimum_earn_amount_minor',
        'redemption_value_minor',
        'rounding_policy',
        'expiration_days',
        'is_active',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'points_per_currency' => 'integer',
            'earn_unit_amount_minor' => 'integer',
            'minimum_earn_amount_minor' => 'integer',
            'redemption_value_minor' => 'integer',
            'expiration_days' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function tiers(): HasMany
    {
        return $this->hasMany(LoyaltyTier::class, 'loyalty_program_id')->orderBy('rank', 'asc');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(CustomerLoyaltyAccount::class, 'loyalty_program_id');
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(LoyaltyReward::class, 'loyalty_program_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(LoyaltyPointLedger::class, 'loyalty_program_id');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(LoyaltyVoucher::class, 'loyalty_program_id');
    }
}
