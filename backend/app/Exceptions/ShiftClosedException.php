<?php

namespace App\Exceptions;

use RuntimeException;

class ShiftClosedException extends RuntimeException
{
    public function __construct(string $message = 'The shift is already closed or not in an open state.')
    {
        parent::__construct($message);
    }
}
