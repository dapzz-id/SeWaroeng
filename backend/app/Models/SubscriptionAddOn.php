<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionAddOn extends Model
{
    use HasFactory, HasUuidV7;

    protected $table = 'subscription_add_ons';

    protected $fillable = [
        'code',
        'name',
        'description',
        'price_minor',
        'feature_key',
        'quota_increment',
        'is_active',
    ];

    protected $casts = [
        'price_minor' => 'integer',
        'quota_increment' => 'integer',
        'is_active' => 'boolean',
    ];

    public function assignments(): HasMany
    {
        return $this->hasMany(SubscriptionAddOnAssignment::class, 'add_on_id');
    }
}
