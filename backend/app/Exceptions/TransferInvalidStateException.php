<?php

namespace App\Exceptions;

use Exception;

class TransferInvalidStateException extends Exception
{
    public function __construct(string $message = 'Stock transfer cannot transition from its current state.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
