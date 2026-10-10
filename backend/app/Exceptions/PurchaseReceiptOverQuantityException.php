<?php

namespace App\Exceptions;

use Exception;

class PurchaseReceiptOverQuantityException extends Exception
{
    public function __construct(string $message = 'Received quantity exceeds the remaining ordered quantity.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
