<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentReconciliationInvalidStateException extends RuntimeException
{
    public function __construct(string $message = 'Invalid payment reconciliation state.')
    {
        parent::__construct($message);
    }
}
