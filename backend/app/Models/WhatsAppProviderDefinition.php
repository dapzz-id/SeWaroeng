<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppProviderDefinition extends Model
{
    use HasFactory, HasUuidV7;

    protected $table = 'whatsapp_provider_definitions';

    protected $fillable = [
        'provider',
        'display_name',
        'is_enabled',
        'adapter_class',
        'capabilities',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'capabilities' => 'array',
        ];
    }
}
