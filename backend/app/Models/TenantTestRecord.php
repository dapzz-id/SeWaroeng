<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use App\Domain\Tenant\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TenantTestRecord extends Model
{
    use BelongsToTenant, HasFactory, HasUuidV7;

    protected $table = 'tenant_test_records';

    protected $fillable = [
        'tenant_id',
        'name',
        'secret_data',
    ];
}
