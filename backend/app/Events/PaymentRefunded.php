<?php

namespace App\Events;

use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PaymentRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Payment $payment,
        public PaymentRefund $refund
    ) {}
}
