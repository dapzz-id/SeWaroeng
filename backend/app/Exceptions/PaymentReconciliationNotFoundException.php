<?php

namespace App\Exceptions;

use RuntimeException;

class PaymentReconciliationNotFoundException extends RuntimeException
{
    public function __construct(string $message = 'Payment reconciliation record not found.')
    {
        parent::__construct($message);
    }
}
