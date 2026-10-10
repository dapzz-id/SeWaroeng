<?php

namespace App\Exceptions;

use Exception;

class OrderInvalidStateException extends Exception
{
    public function __construct(string $message = 'Order is in an invalid state for this operation.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
