<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'journal_lines';

    protected $fillable = [
        'tenant_id',
        'journal_entry_id',
        'account_id',
        'debit_minor',
        'credit_minor',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'debit_minor' => 'integer',
            'credit_minor' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (JournalLine $line) {
            $entry = $line->entry ?: JournalEntry::find($line->journal_entry_id);
            if ($entry && ($entry->isPosted() || $entry->isReversed())) {
                throw new DomainException('Lines of posted or reversed journal entries cannot be modified.');
            }
        });

        static::deleting(function (JournalLine $line) {
            $entry = $line->entry ?: JournalEntry::find($line->journal_entry_id);
            if ($entry && ($entry->isPosted() || $entry->isReversed())) {
                throw new DomainException('Lines of posted or reversed journal entries cannot be deleted.');
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
