<?php

namespace App\Exceptions;

use DomainException;

class RecipeCycleDetectedException extends DomainException
{
    public string $domainCode = 'RECIPE_CYCLE_DETECTED';

    public function __construct(string $message = 'Cyclic BOM graph detected. A recipe component directly or indirectly references itself.')
    {
        parent::__construct($message);
    }
}
