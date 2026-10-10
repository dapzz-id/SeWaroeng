<?php

namespace App\Exceptions;

use Exception;

class SupplierInactiveException extends Exception
{
    public function __construct(string $message = 'Cannot create purchase order with inactive supplier.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
