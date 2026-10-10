<?php

namespace App\Exceptions;

use RuntimeException;

class InsufficientDrawerCashException extends RuntimeException
{
    public function __construct(string $message = 'Cash out or adjustment exceeds the current cash drawer balance.')
    {
        parent::__construct($message);
    }
}
