<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    public const SOURCE_SALE = 'sale';

    public const SOURCE_REFUND = 'payment_refund';

    public const SOURCE_EXPENSE = 'expense';

    public const SOURCE_EXPENSE_REVERSAL = 'expense_reversal';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_CASH_MOVEMENT = 'cash_movement';

    protected $table = 'journal_entries';

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'entry_number',
        'entry_date',
        'status',
        'source_type',
        'source_id',
        'description',
        'posted_at',
        'posted_by',
        'reversed_at',
        'reversed_by',
        'reversal_of_id',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (JournalEntry $entry) {
            // Only allow transition from draft to posted, or posted to reversed
            $originalStatus = $entry->getOriginal('status');
            if ($originalStatus === self::STATUS_POSTED) {
                // If already posted, only allow changing status to reversed with reversal metadata
                $dirtyFields = array_keys($entry->getDirty());
                $allowedOnReversal = ['status', 'reversed_at', 'reversed_by', 'reversal_of_id', 'updated_at'];
                $disallowed = array_diff($dirtyFields, $allowedOnReversal);

                if (! empty($disallowed)) {
                    throw new DomainException('Posted journal entries are strictly immutable and cannot be modified.');
                }

                if ($entry->status !== self::STATUS_REVERSED) {
                    throw new DomainException('Posted journal entries can only be transitioned to reversed status.');
                }
            } elseif ($originalStatus === self::STATUS_REVERSED) {
                throw new DomainException('Reversed journal entries are immutable.');
            }
        });

        static::deleting(function (JournalEntry $entry) {
            if ($entry->status === self::STATUS_POSTED || $entry->status === self::STATUS_REVERSED) {
                throw new DomainException('Posted or reversed journal entries cannot be deleted.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'journal_entry_id');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reverser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }

    public function isBalanced(): bool
    {
        $debits = (int) $this->lines->sum('debit_minor');
        $credits = (int) $this->lines->sum('credit_minor');

        return $debits > 0 && $debits === $credits;
    }
}
