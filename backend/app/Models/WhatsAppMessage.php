<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WhatsAppMessage extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_READ = 'read';

    public const STATUS_FAILED = 'failed';

    public const STATUS_AMBIGUOUS_DELIVERY = 'ambiguous_delivery';

    public const TYPE_TRANSACTIONAL_RECEIPT = 'transactional_receipt';

    public const TYPE_TRANSACTIONAL_ORDER = 'transactional_order';

    public const TYPE_LOYALTY_NOTIFICATION = 'loyalty_notification';

    public const TYPE_MARKETING_PROMO = 'marketing_promo';

    // PRD Section 19 Merchant-side notification types
    public const TYPE_MERCHANT_DAILY_SUMMARY = 'merchant_daily_summary';

    public const TYPE_MERCHANT_LOW_STOCK = 'merchant_low_stock';

    public const TYPE_MERCHANT_BOOKING_REMINDER = 'merchant_booking_reminder';

    public const TYPE_MERCHANT_SETTLEMENT = 'merchant_settlement';

    public const TYPE_MERCHANT_ANOMALY = 'merchant_anomaly';

    public const TYPE_MERCHANT_QUERY_REPLY = 'merchant_query_reply';

    // PRD Section 19 Customer-side message types
    public const TYPE_ORDER_CONFIRMATION = 'order_confirmation';

    public const TYPE_BOOKING_CONFIRMATION = 'booking_confirmation';

    public const TYPE_BOOKING_REMINDER = 'booking_reminder';

    public const TYPE_ORDER_STATUS_UPDATE = 'order_status_update';

    /**
     * Determine if this message is a merchant-directed notification.
     */
    public function isMerchantMessage(): bool
    {
        return in_array($this->message_type, [
            self::TYPE_MERCHANT_DAILY_SUMMARY,
            self::TYPE_MERCHANT_LOW_STOCK,
            self::TYPE_MERCHANT_BOOKING_REMINDER,
            self::TYPE_MERCHANT_SETTLEMENT,
            self::TYPE_MERCHANT_ANOMALY,
            self::TYPE_MERCHANT_QUERY_REPLY,
        ], true);
    }

    protected $table = 'whatsapp_messages';

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'customer_id',
        'recipient_phone',
        'message_type',
        'template_name',
        'payload',
        'status',
        'provider',
        'provider_message_id',
        'idempotency_key',
        'attempts',
        'last_attempt_at',
        'error_code',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
        'failed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'attempts' => 'integer',
            'last_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isSent(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_READ], true);
    }

    public function isDelivered(): bool
    {
        return in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_READ], true);
    }

    public function isRead(): bool
    {
        return $this->status === self::STATUS_READ;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isAmbiguous(): bool
    {
        return $this->status === self::STATUS_AMBIGUOUS_DELIVERY;
    }

    /**
     * Mark message delivery state as ambiguous (e.g. provider timeout or connection crash).
     * Prevents blind re-transmission while awaiting webhook or careful recovery audit.
     */
    public function markAmbiguousDelivery(?string $reason = null, ?string $errorCode = null): void
    {
        if (in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_READ], true)) {
            return;
        }

        $this->update([
            'status' => self::STATUS_AMBIGUOUS_DELIVERY,
            'error_code' => $errorCode ?? 'AMBIGUOUS_DELIVERY',
            'error_message' => $reason ?? 'Ambiguous delivery: provider response unconfirmed before worker completion.',
            'last_attempt_at' => now(),
        ]);
    }

    /**
     * Mark message as actively transmitting to provider.
     */
    public function markSending(): void
    {
        if (in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_READ], true)) {
            return;
        }

        $this->update([
            'status' => self::STATUS_SENDING,
            'attempts' => $this->attempts + 1,
            'last_attempt_at' => now(),
        ]);
    }

    /**
     * Verify whether a status transition satisfies the monotonic state machine.
     */
    public function canTransitionTo(string $targetStatus): bool
    {
        if ($this->status === self::STATUS_READ) {
            return $targetStatus === self::STATUS_READ;
        }

        if ($this->status === self::STATUS_DELIVERED) {
            return in_array($targetStatus, [self::STATUS_DELIVERED, self::STATUS_READ], true);
        }

        if ($this->status === self::STATUS_SENT) {
            return in_array($targetStatus, [self::STATUS_SENT, self::STATUS_DELIVERED, self::STATUS_READ], true);
        }

        return true;
    }

    /**
     * Mark message as successfully accepted by provider.
     */
    public function markSent(string $providerMessageId): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'provider_message_id' => $providerMessageId,
            'sent_at' => now(),
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    /**
     * Mark message as delivered to recipient handset.
     * Enforces monotonic state transition (cannot downgrade from read).
     */
    public function markDelivered(): void
    {
        if ($this->status === self::STATUS_READ) {
            return; // Already in higher state
        }

        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => $this->delivered_at ?? now(),
        ]);
    }

    /**
     * Mark message as read by recipient.
     * Enforces monotonic state transition.
     */
    public function markRead(): void
    {
        $this->update([
            'status' => self::STATUS_READ,
            'delivered_at' => $this->delivered_at ?? now(),
            'read_at' => $this->read_at ?? now(),
        ]);
    }

    /**
     * Mark message transmission failure.
     * Guarded against late out-of-order callbacks when already confirmed delivered or read.
     */
    public function markFailed(string $errorCode, string $errorMessage): void
    {
        if (in_array($this->status, [self::STATUS_DELIVERED, self::STATUS_READ], true)) {
            return; // Cannot downgrade a confirmed delivery
        }

        $this->update([
            'status' => self::STATUS_FAILED,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'failed_at' => now(),
        ]);
    }
}
