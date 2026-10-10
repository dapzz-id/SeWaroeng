<?php

namespace App\Events;

use App\Models\StockOpname;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryOpnameApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public StockOpname $opname
    ) {}
}
