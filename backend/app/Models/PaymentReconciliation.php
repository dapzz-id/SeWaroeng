<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReconciliation extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_UNMATCHED = 'unmatched';

    public const STATUS_MATCHED = 'matched';

    public const STATUS_MISMATCHED = 'mismatched';

    public const STATUS_NEEDS_REVIEW = 'needs_review';

    public const STATUS_RESOLVED = 'resolved';

    public const SETTLEMENT_PENDING = 'pending';

    public const SETTLEMENT_SETTLED = 'settled';

    public const SETTLEMENT_FAILED = 'failed';

    public const SETTLEMENT_DISPUTED = 'disputed';

    public const MISMATCH_AMOUNT = 'AMOUNT_MISMATCH';

    public const MISMATCH_CURRENCY = 'CURRENCY_MISMATCH';

    public const MISMATCH_PAYMENT_NOT_FOUND = 'PAYMENT_NOT_FOUND';

    public const MISMATCH_STATUS_CONFLICT = 'STATUS_CONFLICT';

    public const MISMATCH_OUTLET_CONFLICT = 'OUTLET_CONFLICT';

    public const MISMATCH_DUPLICATE_REFERENCE = 'DUPLICATE_REFERENCE';

    protected $table = 'payment_reconciliations';

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'payment_id',
        'provider',
        'provider_reference',
        'channel',
        'expected_amount_minor',
        'provider_amount_minor',
        'fee_amount_minor',
        'net_amount_minor',
        'currency',
        'payment_status',
        'settlement_status',
        'reconciliation_status',
        'mismatch_reason',
        'reconciled_at',
        'reconciled_by',
        'resolved_at',
        'resolved_by',
        'resolution_notes',
        'raw_evidence',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'expected_amount_minor' => 'integer',
            'provider_amount_minor' => 'integer',
            'fee_amount_minor' => 'integer',
            'net_amount_minor' => 'integer',
            'reconciled_at' => 'datetime',
            'resolved_at' => 'datetime',
            'raw_evidence' => 'array',
            'metadata' => 'array',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function reconciler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reconciled_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isMatched(): bool
    {
        return $this->reconciliation_status === self::STATUS_MATCHED;
    }

    public function isUnmatched(): bool
    {
        return $this->reconciliation_status === self::STATUS_UNMATCHED;
    }

    public function isMismatched(): bool
    {
        return $this->reconciliation_status === self::STATUS_MISMATCHED;
    }

    public function isNeedsReview(): bool
    {
        return $this->reconciliation_status === self::STATUS_NEEDS_REVIEW;
    }

    public function isResolved(): bool
    {
        return $this->reconciliation_status === self::STATUS_RESOLVED;
    }
}
