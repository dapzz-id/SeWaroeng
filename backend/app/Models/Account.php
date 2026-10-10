<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const TYPE_ASSET = 'asset';

    public const TYPE_LIABILITY = 'liability';

    public const TYPE_EQUITY = 'equity';

    public const TYPE_REVENUE = 'revenue';

    public const TYPE_EXPENSE = 'expense';

    // Standard Default System Account Codes
    public const CODE_CASH = '1110';

    public const CODE_BANK_CLEARING = '1120';

    public const CODE_INVENTORY = '1200';

    public const CODE_TAX_PAYABLE = '2110';

    public const CODE_OWNER_EQUITY = '3100';

    public const CODE_SALES_REVENUE = '4100';

    public const CODE_SALES_RETURN = '4110';

    public const CODE_COGS = '5100';

    public const CODE_OPERATING_EXPENSE = '6100';

    protected $table = 'accounts';

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'type',
        'is_system',
        'is_active',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class, 'account_id');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'account_id');
    }
}
