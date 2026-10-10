<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SaleCancelled
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Sale $sale
    ) {}
}
