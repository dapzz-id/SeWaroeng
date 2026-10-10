<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Notification extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const TYPE_LOW_STOCK = 'low_stock';

    public const TYPE_SHIFT_VARIANCE = 'shift_variance';

    public const TYPE_PAYMENT_STATUS = 'payment_status';

    public const TYPE_PAYMENT_FAILED = 'payment_failed';

    public const TYPE_BOOKING_CREATED = 'booking_created';

    public const TYPE_ORDER_RECEIVED = 'order_received';

    public const SEVERITY_INFO = 'info';

    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_CRITICAL = 'critical';

    protected $table = 'notifications';

    protected $fillable = [
        'tenant_id',
        'recipient_user_id',
        'type',
        'title',
        'message',
        'severity',
        'data',
        'dedup_key',
        'read_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function isRead(): bool
    {
        return $this->read_at !== null;
    }

    public function markAsRead(): void
    {
        if (! $this->isRead()) {
            $this->update(['read_at' => now()]);
        }
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function markAsResolved(): void
    {
        if (! $this->isResolved()) {
            $this->update(['resolved_at' => now()]);
        }
    }
}
