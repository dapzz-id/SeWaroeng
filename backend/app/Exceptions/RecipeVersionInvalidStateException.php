<?php

namespace App\Exceptions;

use DomainException;

class RecipeVersionInvalidStateException extends DomainException
{
    public string $domainCode = 'RECIPE_VERSION_NOT_ACTIVE';
}
