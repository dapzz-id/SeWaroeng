<?php

namespace App\Exceptions;

use RuntimeException;

class ActiveShiftAlreadyExistsException extends RuntimeException
{
    public function __construct(string $message = 'An active shift is already open for this outlet.')
    {
        parent::__construct($message);
    }
}
