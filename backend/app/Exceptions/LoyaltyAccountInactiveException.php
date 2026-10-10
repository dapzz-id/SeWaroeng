<?php

namespace App\Exceptions;

use Exception;

class LoyaltyAccountInactiveException extends Exception
{
    public function __construct(string $message = 'Akun loyalitas pelanggan sedang tidak aktif atau ditangguhkan.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
