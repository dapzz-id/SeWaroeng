<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Illuminate\Database\Eloquent\Relations\Pivot;

class OutletUserAccess extends Pivot
{
    use HasUuidV7;

    protected $table = 'outlet_user_access';

    public $incrementing = false;

    protected $keyType = 'string';
}
