<?php

namespace App\Exceptions;

use Exception;

class SaleInvalidStateException extends Exception
{
    public function __construct(string $message = 'Invalid sale state transition.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
