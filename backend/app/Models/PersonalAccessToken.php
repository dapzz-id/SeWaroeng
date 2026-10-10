<?php

namespace App\Models;

use App\Domain\Shared\Concerns\HasUuidV7;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use HasUuidV7;

    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'device_id',
        'platform',
        'ip_address',
        'last_used_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'revoked_at' => 'datetime',
        ]);
    }

    public static function findToken($token)
    {
        $tokenModel = parent::findToken($token);

        if (! $tokenModel || $tokenModel->revoked_at !== null) {
            return null;
        }

        return $tokenModel;
    }
}
