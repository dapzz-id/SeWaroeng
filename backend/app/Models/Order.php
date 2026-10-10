<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_SUBMITTED = 'submitted';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_PREPARING = 'preparing';

    public const STATUS_READY = 'ready';

    public const STATUS_SERVED = 'served';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_CONVERTED = 'converted';

    protected $table = 'orders';

    protected $fillable = [
        'id',
        'tenant_id',
        'outlet_id',
        'table_id',
        'table_session_id',
        'customer_id',
        'sale_id',
        'order_number',
        'customer_name_snapshot',
        'customer_contact_snapshot',
        'status',
        'subtotal_minor',
        'discount_minor',
        'tax_minor',
        'total_minor',
        'customer_notes',
        'idempotency_key',
        'public_tracking_token_hash',
        'public_tracking_token_ciphertext',
        'source',
        'metadata',
    ];

    /**
     * Prevent internal security hash and ciphertext replay credentials leakage.
     */
    protected $hidden = [
        'public_tracking_token_hash',
        'public_tracking_token_ciphertext',
    ];

    /**
     * Transient raw tracking token, only returned to customer upon order creation.
     */
    public ?string $raw_tracking_token = null;

    /**
     * Hash raw public tracking token using SHA-256.
     */
    public static function hashTrackingToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class, 'table_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'customer_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'sale_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    public function isSubmitted(): bool
    {
        return $this->status === self::STATUS_SUBMITTED;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function isConverted(): bool
    {
        return $this->status === self::STATUS_CONVERTED;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }
}
