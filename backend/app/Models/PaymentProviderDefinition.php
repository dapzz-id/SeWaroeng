<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentProviderDefinition extends Model
{
    use HasFactory, HasUuidV7;

    protected $table = 'payment_provider_definitions';

    protected $fillable = [
        'provider',
        'display_name',
        'is_enabled',
        'adapter_class',
        'supported_methods',
        'capabilities',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'supported_methods' => 'array',
            'capabilities' => 'array',
        ];
    }
}
