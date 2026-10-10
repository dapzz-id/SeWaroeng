<?php

namespace App\Exceptions;

use Exception;

class PriceNotConfiguredException extends Exception
{
    public function __construct(string $message = 'No active price configured for this variant.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
