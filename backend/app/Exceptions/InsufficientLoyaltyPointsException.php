<?php

namespace App\Exceptions;

use Exception;

class InsufficientLoyaltyPointsException extends Exception
{
    public function __construct(string $message = 'Poin loyalitas tidak mencukupi untuk melakukan penukaran reward.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
