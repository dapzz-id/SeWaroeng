<?php

namespace App\Exceptions;

use DomainException;

class RecipeNotConfiguredException extends DomainException
{
    public string $domainCode = 'RECIPE_NOT_CONFIGURED';

    public function __construct(string $message = 'No published recipe configured for this product variant.')
    {
        parent::__construct($message);
    }
}
