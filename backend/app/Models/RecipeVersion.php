<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RecipeVersion extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'tenant_id',
        'recipe_id',
        'version_number',
        'status',
        'effective_from',
        'effective_until',
        'yield_quantity',
        'yield_unit',
        'notes',
        'created_by',
        'published_by',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'version_number' => 'integer',
            'yield_quantity' => 'decimal:4',
            'effective_from' => 'datetime',
            'effective_until' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (RecipeVersion $version) {
            // Published versions are immutable in their definitional fields.
            // Only status transitions are allowed via explicit service methods.
            if ($version->getOriginal('status') === self::STATUS_PUBLISHED) {
                $mutableOnPublished = ['status', 'effective_until'];
                $changed = array_keys($version->getDirty());
                $illegalChanges = array_diff($changed, $mutableOnPublished);
                if (! empty($illegalChanges)) {
                    throw new DomainException(
                        'Published recipe versions are immutable. Cannot change: '.implode(', ', $illegalChanges)
                    );
                }
            }
        });
    }

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'recipe_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(RecipeComponent::class, 'recipe_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    /**
     * Returns the effective quantity per portion for a component.
     * Formula: (component_quantity * (1 + waste_factor)) / yield_quantity
     * This is for informational purposes in cost estimation — not rounded.
     */
    public function perPortionQuantity(string $componentQty, string $wasteFactor): string
    {
        $grossQty = bcmul($componentQty, bcadd('1', $wasteFactor, 6), 6);

        return bcdiv($grossQty, (string) $this->yield_quantity, 6);
    }
}
