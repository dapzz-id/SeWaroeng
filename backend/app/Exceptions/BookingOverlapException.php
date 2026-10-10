<?php

namespace App\Exceptions;

use Exception;

class BookingOverlapException extends Exception
{
    public function __construct(string $message = 'Table is already booked during this time interval.', int $code = 422, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
