<?php

namespace App\Exceptions;

use RuntimeException;

class InventoryLocationOutletMismatchException extends RuntimeException
{
    public function __construct(string $message = 'Inventory location does not belong to the transaction outlet.')
    {
        parent::__construct($message);
    }
}
