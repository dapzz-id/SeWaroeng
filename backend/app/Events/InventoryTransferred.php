<?php

namespace App\Events;

use App\Models\StockTransfer;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InventoryTransferred
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public StockTransfer $transfer
    ) {}
}
