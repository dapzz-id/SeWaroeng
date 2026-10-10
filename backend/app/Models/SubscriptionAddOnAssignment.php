<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionAddOnAssignment extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'subscription_add_on_assignments';

    protected $fillable = [
        'tenant_id',
        'subscription_id',
        'add_on_id',
        'quantity',
        'status',
        'starts_at',
        'ends_at',
    ];

    protected $casts = [
        'quantity' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function addOn(): BelongsTo
    {
        return $this->belongsTo(SubscriptionAddOn::class, 'add_on_id');
    }
}
