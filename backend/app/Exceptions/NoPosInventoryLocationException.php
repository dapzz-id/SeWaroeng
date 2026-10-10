<?php

namespace App\Exceptions;

use RuntimeException;

class NoPosInventoryLocationException extends RuntimeException
{
    public function __construct(string $message = 'No active inventory location configured for this outlet. POS cannot consume stock.')
    {
        parent::__construct($message);
    }
}
