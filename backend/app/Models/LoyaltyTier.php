<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LoyaltyTier extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'loyalty_tiers';

    protected $fillable = [
        'tenant_id',
        'loyalty_program_id',
        'name',
        'rank',
        'min_points_earned',
        'multiplier_bps',
        'perks',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'rank' => 'integer',
            'min_points_earned' => 'integer',
            'multiplier_bps' => 'integer',
            'perks' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'loyalty_program_id');
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(CustomerLoyaltyAccount::class, 'tier_id');
    }
}
