<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ManualCashPaymentProvider extends AbstractPaymentProvider
{
    public const PROVIDER_NAME = 'manual';

    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function initiatePayment(Payment $payment, array $options = []): array
    {
        return [
            'status' => Payment::STATUS_SUCCEEDED,
            'provider_reference' => 'CASH-'.strtoupper(Str::random(8)),
            'response_code' => 'CASH_RECEIVED',
            'response_payload' => [
                'type' => 'physical_cash',
                'tendered_at' => now()->toIso8601String(),
            ],
        ];
    }

    public function queryPayment(Payment $payment, array $options = []): array
    {
        return [
            'status' => $payment->status,
            'provider_reference' => $payment->provider_payment_id,
            'response_code' => 'CASH_VERIFIED',
            'response_payload' => [],
        ];
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        return false;
    }

    public function parseWebhookEvent(Request $request): array
    {
        return [
            'external_event_id' => Str::uuid()->toString(),
            'provider_payment_id' => null,
            'status' => Payment::STATUS_SUCCEEDED,
            'amount_minor' => null,
            'currency' => null,
            'payload' => $request->all(),
        ];
    }

    public function processRefund(PaymentRefund $refund): array
    {
        return [
            'status' => PaymentRefund::STATUS_COMPLETED,
            'provider_reference' => 'CASH-REF-'.strtoupper(Str::random(8)),
            'response_code' => 'CASH_DISBURSED',
            'response_payload' => [
                'type' => 'physical_cash_refund',
                'disbursed_at' => now()->toIso8601String(),
            ],
        ];
    }
}
