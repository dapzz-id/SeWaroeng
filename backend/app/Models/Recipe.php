<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use DomainException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recipe extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'tenant_id',
        'product_variant_id',
        'inventory_item_id',
        'name',
        'code',
        'status',
        'created_by',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Recipe $recipe) {
            if (in_array($recipe->status, [self::STATUS_PUBLISHED, self::STATUS_ARCHIVED])) {
                throw new DomainException('Published or archived recipes cannot be deleted. Archive it instead.');
            }
        });
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function isVariantRecipe(): bool
    {
        return $this->product_variant_id !== null;
    }

    public function isItemRecipe(): bool
    {
        return $this->inventory_item_id !== null;
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RecipeVersion::class, 'recipe_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::STATUS_DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }
}
