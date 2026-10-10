<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory, HasUuidV7;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'actor_user_id',
        'action',
        'entity_type',
        'entity_id',
        'before',
        'after',
        'request_id',
        'ip_address',
        'user_agent',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function setBeforeAttribute($value): void
    {
        $this->attributes['before'] = is_array($value) ? json_encode(self::sanitizePayload($value)) : $value;
    }

    public function setAfterAttribute($value): void
    {
        $this->attributes['after'] = is_array($value) ? json_encode(self::sanitizePayload($value)) : $value;
    }

    /**
     * Recursively redacts sensitive attributes.
     */
    public static function sanitizePayload(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }

        $sensitivePatterns = [
            'password', 'passwd', 'pin', 'pin_hash', 'secret', 'token',
            'api_key', 'access_token', 'credit_card', 'cvv', 'authorization',
            'pan', 'card_number', 'private_key', 'signature', 'signature_key',
            'server_key', 'client_key',
        ];

        $sanitized = [];
        foreach ($payload as $key => $value) {
            $lowerKey = strtolower((string) $key);
            $isSensitive = false;
            foreach ($sensitivePatterns as $pattern) {
                if (str_contains($lowerKey, $pattern)) {
                    $isSensitive = true;
                    break;
                }
            }

            if ($isSensitive) {
                $sanitized[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $sanitized[$key] = self::sanitizePayload($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
