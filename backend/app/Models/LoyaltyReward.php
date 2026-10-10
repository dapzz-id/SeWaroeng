<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyReward extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const TYPE_DISCOUNT_FIXED = 'discount_fixed';

    public const TYPE_DISCOUNT_PERCENT = 'discount_percent';

    public const TYPE_FREE_ITEM = 'free_item';

    protected $table = 'loyalty_rewards';

    protected $fillable = [
        'tenant_id',
        'loyalty_program_id',
        'name',
        'description',
        'points_cost',
        'reward_type',
        'discount_amount_minor',
        'discount_percent',
        'min_purchase_minor',
        'stock',
        'validity_days',
        'is_active',
        'valid_from',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'points_cost' => 'integer',
            'discount_amount_minor' => 'integer',
            'discount_percent' => 'integer',
            'min_purchase_minor' => 'integer',
            'stock' => 'integer',
            'validity_days' => 'integer',
            'is_active' => 'boolean',
            'valid_from' => 'datetime',
            'valid_until' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(LoyaltyVoucher::class, 'reward_id');
    }

    public function isAvailable(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->valid_from && now()->lt($this->valid_from)) {
            return false;
        }

        if ($this->valid_until && now()->gt($this->valid_until)) {
            return false;
        }

        if ($this->stock !== null && $this->stock <= 0) {
            return false;
        }

        return true;
    }
}
