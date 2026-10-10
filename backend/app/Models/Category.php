<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $fillable = [
        'tenant_id',
        'parent_id',
        'name',
        'slug',
        'sort_order',
        'status',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id')->orderBy('sort_order');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    /**
     * Checks if assigning the given parent ID would cause a circular ancestor cycle.
     */
    public function wouldCreateCycle(?string $newParentId): bool
    {
        if (empty($newParentId)) {
            return false;
        }

        if ((string) $this->id === (string) $newParentId) {
            return true;
        }

        $visited = [(string) $this->id];
        $curr = Category::find($newParentId);

        while ($curr) {
            if (in_array((string) $curr->id, $visited, true)) {
                return true;
            }
            $visited[] = (string) $curr->id;
            $curr = $curr->parent;
        }

        return false;
    }
}
