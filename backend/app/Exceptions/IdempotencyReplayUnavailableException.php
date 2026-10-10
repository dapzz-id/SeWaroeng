<?php

namespace App\Exceptions;

use RuntimeException;

class IdempotencyReplayUnavailableException extends RuntimeException
{
    public function __construct(string $message = 'Unable to replay idempotent request because credential replay data is unavailable.')
    {
        parent::__construct($message);
    }
}
