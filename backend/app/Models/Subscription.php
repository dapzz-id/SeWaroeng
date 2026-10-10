<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAST_DUE = 'past_due';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    protected $table = 'subscriptions';

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status',
        'starts_at',
        'current_period_start',
        'current_period_end',
        'trial_ends_at',
        'cancelled_at',
        'ended_at',
        'billing_provider',
        'metadata',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'current_period_start' => 'datetime',
        'current_period_end' => 'datetime',
        'trial_ends_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'ended_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriptionEvent::class, 'subscription_id')->orderByDesc('created_at');
    }

    public function addOnAssignments(): HasMany
    {
        return $this->hasMany(SubscriptionAddOnAssignment::class, 'subscription_id');
    }

    /**
     * Determines if the subscription provides active operational access.
     */
    public function isOperational(): bool
    {
        $now = Carbon::now();

        if ($this->status === self::STATUS_TRIALING) {
            return $this->trial_ends_at ? $now->lte($this->trial_ends_at) : true;
        }

        if ($this->status === self::STATUS_ACTIVE) {
            return true;
        }

        if ($this->status === self::STATUS_CANCELLED) {
            // Cancelled subscriptions retain access until end of current prepaid period
            return $now->lte($this->current_period_end);
        }

        return false;
    }

    /**
     * Determines if subscription is currently in a trial period.
     */
    public function isTrial(): bool
    {
        return $this->status === self::STATUS_TRIALING;
    }
}
