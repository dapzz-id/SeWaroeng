<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionEvent extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const EVENT_TRIAL_STARTED = 'trial_started';

    public const EVENT_PLAN_ASSIGNED = 'plan_assigned';

    public const EVENT_PLAN_UPGRADED = 'plan_upgraded';

    public const EVENT_PLAN_DOWNGRADED = 'plan_downgraded';

    public const EVENT_SUBSCRIPTION_CANCELLED = 'subscription_cancelled';

    public const EVENT_SUBSCRIPTION_RENEWED = 'subscription_renewed';

    public const EVENT_SUBSCRIPTION_EXPIRED = 'subscription_expired';

    public const EVENT_ADDON_ASSIGNED = 'addon_assigned';

    public const EVENT_ADDON_REMOVED = 'addon_removed';

    protected $table = 'subscription_events';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'event_type',
        'user_id',
        'from_plan_id',
        'to_plan_id',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function fromPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'from_plan_id');
    }

    public function toPlan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'to_plan_id');
    }
}
