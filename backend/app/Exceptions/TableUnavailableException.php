<?php

namespace App\Exceptions;

use Exception;

class TableUnavailableException extends Exception
{
    public function __construct(string $message = 'Table is currently occupied or inactive.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
