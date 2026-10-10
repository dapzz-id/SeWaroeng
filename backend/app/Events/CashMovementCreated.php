<?php

namespace App\Events;

use App\Models\CashMovement;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CashMovementCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public CashMovement $movement) {}
}
