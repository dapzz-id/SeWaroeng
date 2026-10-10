<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    public const PAYMENT_METHOD_CASH = 'cash';

    public const PAYMENT_METHOD_BANK = 'bank';

    public const CATEGORY_UTILITIES = 'utilities';

    public const CATEGORY_SUPPLIES = 'supplies';

    public const CATEGORY_RENT = 'rent';

    public const CATEGORY_SALARY = 'salary';

    public const CATEGORY_MAINTENANCE = 'maintenance';

    public const CATEGORY_MARKETING = 'marketing';

    public const CATEGORY_OTHER = 'other';

    protected $table = 'expenses';

    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'account_id',
        'category',
        'amount_minor',
        'expense_date',
        'payment_method',
        'status',
        'description',
        'reference_number',
        'recorded_by',
        'posted_at',
        'reversed_at',
        'reversed_by',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'expense_date' => 'date',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Expense $expense) {
            $originalStatus = $expense->getOriginal('status');
            if ($originalStatus === self::STATUS_POSTED) {
                // If already posted, only allow transitioning to reversed with reversal timestamps
                $dirtyFields = array_keys($expense->getDirty());
                $allowedOnReversal = ['status', 'reversed_at', 'reversed_by', 'updated_at'];
                $disallowed = array_diff($dirtyFields, $allowedOnReversal);

                if (! empty($disallowed)) {
                    throw new DomainException('Posted expenses cannot be modified. A posted expense must be reversed instead.');
                }
            } elseif ($originalStatus === self::STATUS_REVERSED) {
                throw new DomainException('Reversed expenses are immutable.');
            }
        });

        static::deleting(function (Expense $expense) {
            if ($expense->status === self::STATUS_POSTED || $expense->status === self::STATUS_REVERSED) {
                throw new DomainException('Posted or reversed expenses cannot be deleted.');
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

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isReversed(): bool
    {
        return $this->status === self::STATUS_REVERSED;
    }
}
