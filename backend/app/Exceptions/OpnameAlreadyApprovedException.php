<?php

namespace App\Exceptions;

use Exception;

class OpnameAlreadyApprovedException extends Exception
{
    public function __construct(string $message = 'This stock opname has already been approved.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
