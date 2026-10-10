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

    public const TYPE_TRANSACTIONAL_RECEIPT = 'transactional_receipt';

    public const TYPE_TRANSACTIONAL_ORDER = 'transactional_order';

    public const TYPE_LOYALTY_NOTIFICATION = 'loyalty_notification';

    public const TYPE_MARKETING_PROMO = 'marketing_promo';

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

    /**
     * Mark message as actively transmitting to provider.
     */
    public function markSending(): void
    {
        $this->update([
            'status' => self::STATUS_SENDING,
            'attempts' => $this->attempts + 1,
            'last_attempt_at' => now(),
        ]);
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
