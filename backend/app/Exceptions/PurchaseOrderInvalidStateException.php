<?php

namespace App\Exceptions;

use Exception;

class PurchaseOrderInvalidStateException extends Exception
{
    public function __construct(string $message = 'Purchase order is in an invalid state for this operation.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
