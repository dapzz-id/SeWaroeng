<?php

namespace App\Events;

use App\Models\Recipe;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RecipePublished
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Recipe $recipe,
        public string $versionId,
    ) {}
}
