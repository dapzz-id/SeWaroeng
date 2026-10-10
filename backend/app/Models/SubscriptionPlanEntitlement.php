<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPlanEntitlement extends Model
{
    use HasFactory, HasUuidV7;

    protected $table = 'subscription_plan_entitlements';

    protected $fillable = [
        'plan_id',
        'feature_key',
        'is_enabled',
        'quota_limit',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'quota_limit' => 'integer',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }
}
