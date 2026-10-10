<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Table extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_AVAILABLE = 'available';

    public const STATUS_OCCUPIED = 'occupied';

    public const STATUS_RESERVED = 'reserved';

    public const STATUS_INACTIVE = 'inactive';

    protected $table = 'tables';

    protected $fillable = [
        'id',
        'tenant_id',
        'outlet_id',
        'code',
        'name',
        'capacity',
        'status',
        'qr_token_hash',
        'qr_token_generated_at',
        'is_active',
        'metadata',
    ];

    /**
     * Ensure the one-way token hash is never leaked through API serialization.
     */
    protected $hidden = [
        'qr_token_hash',
    ];

    /**
     * Transient in-memory raw token, only present immediately after generation.
     */
    public ?string $raw_token = null;

    /**
     * Accessor for backward-compatible code referencing $table->qr_token in memory.
     */
    public function getQrTokenAttribute(): ?string
    {
        return $this->raw_token;
    }

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
            'metadata' => 'array',
            'qr_token_generated_at' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class, 'table_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TableSession::class, 'table_id');
    }

    public function activeSession(): HasOne
    {
        return $this->hasOne(TableSession::class, 'table_id')->where('status', TableSession::STATUS_ACTIVE);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'table_id');
    }

    public function isAvailable(): bool
    {
        return $this->status === self::STATUS_AVAILABLE && $this->is_active;
    }

    /**
     * Compute cryptographically secure sha256 hash of a raw QR token.
     */
    public static function hashToken(string $rawToken): string
    {
        return hash('sha256', $rawToken);
    }

    /**
     * Generates or rotates a secure, unguessable public QR token.
     * Persists only the sha256 one-way hash in the database.
     * Returns the transient raw token for QR generation.
     */
    public function generateQrToken(): string
    {
        $rawToken = bin2hex(random_bytes(32)); // 64 hex characters
        $this->qr_token_hash = self::hashToken($rawToken);
        $this->qr_token_generated_at = now();
        $this->save();
        $this->raw_token = $rawToken;

        return $rawToken;
    }

    /**
     * Revokes the current QR token to immediately cut off public customer access.
     */
    public function revokeQrToken(): void
    {
        $this->qr_token_hash = null;
        $this->qr_token_generated_at = null;
        $this->raw_token = null;
        $this->save();
    }
}
