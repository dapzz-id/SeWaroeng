<?php

namespace App\Exceptions;

use Exception;

class SubscriptionQuotaExceededException extends Exception
{
    public function __construct(
        string $message = 'Subscription quota limit exceeded for this resource.',
        int $code = 422,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
