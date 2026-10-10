<?php

namespace App\Exceptions;

use Exception;

class SubscriptionFeatureNotIncludedException extends Exception
{
    public function __construct(
        string $message = 'Feature not included in current subscription plan.',
        int $code = 403,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }
}
