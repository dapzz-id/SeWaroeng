<?php

namespace App\Exceptions;

use Exception;

class SubscriptionInactiveException extends Exception
{
    public function __construct(
        string $message = 'Subscription is inactive, expired, or past due.',
        int $code = 403,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
