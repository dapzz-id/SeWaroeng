<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantPaymentConnection extends Model
{
    use HasFactory, HasUuidV7;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const VERIFICATION_UNVERIFIED = 'unverified';

    public const VERIFICATION_VERIFIED = 'verified';

    public const VERIFICATION_FAILED = 'failed';

    protected $table = 'tenant_payment_connections';

    protected $fillable = [
        'tenant_id',
        'provider',
        'environment',
        'status',
        'is_default',
        'merchant_identifier',
        'encrypted_credentials',
        'configuration',
        'verification_status',
        'capability_status',
        'verified_at',
        'last_error_code',
        'last_error_message',
        'created_by',
        'updated_by',
    ];

    protected $hidden = [
        'encrypted_credentials',
    ];

    protected $appends = [
        'masked_credentials',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'encrypted_credentials' => 'encrypted:array',
            'configuration' => 'array',
            'capability_status' => 'array',
            'verified_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $connection) {
            // Check if security identity changed
            $credentialsChanged = $connection->isDirty('encrypted_credentials');
            $merchantIdChanged = $connection->isDirty('merchant_identifier');
            $environmentChanged = $connection->isDirty('environment');

            if ($credentialsChanged || $merchantIdChanged || $environmentChanged) {
                $connection->verification_status = self::VERIFICATION_UNVERIFIED;
                $connection->verified_at = null;
                $connection->last_error_code = null;
                $connection->last_error_message = null;
                $connection->is_default = false;
            }

            // Inactive connection cannot be default
            if ($connection->status === self::STATUS_INACTIVE && $connection->is_default) {
                $connection->is_default = false;
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isVerified(): bool
    {
        return $this->verification_status === self::VERIFICATION_VERIFIED;
    }

    public function supportsMethod(string $method): bool
    {
        $methods = $this->capability_status['supported_methods'] ?? ($this->configuration['enabled_methods'] ?? null);
        if ($methods === null) {
            return true;
        }

        return in_array(strtolower($method), array_map('strtolower', (array) $methods), true);
    }

    public function supportsRefund(): bool
    {
        return (bool) ($this->capability_status['refund'] ?? false);
    }

    /**
     * Return masked credentials safe for API responses and client serialization.
     *
     * @return array<string, string>
     */
    public function getMaskedCredentialsAttribute(): array
    {
        $raw = $this->encrypted_credentials ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $masked = [];
        foreach ($raw as $key => $value) {
            $masked[$key] = self::maskSecret((string) $value);
        }

        return $masked;
    }

    /**
     * Mask sensitive secret strings so only safe suffix is visible.
     */
    public static function maskSecret(?string $secret): string
    {
        if (empty($secret)) {
            return '';
        }

        $len = strlen($secret);
        if ($len <= 4) {
            return '****';
        }

        $suffix = substr($secret, -4);
        $maskLength = max(8, $len - 4);

        return str_repeat('*', $maskLength).$suffix;
    }
}
