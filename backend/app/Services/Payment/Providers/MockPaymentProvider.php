<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;
use App\Models\PaymentRefund;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MockPaymentProvider extends AbstractPaymentProvider
{
    public const PROVIDER_NAME = 'mock';

    public function supportsRefund(?Payment $payment = null): bool
    {
        return true;
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function initiatePayment(Payment $payment, array $options = []): array
    {
        $metadata = $payment->metadata ?? [];

        if (! empty($metadata['simulate_failure'])) {
            return [
                'status' => Payment::STATUS_FAILED,
                'provider_reference' => 'MOCK-FAIL-'.strtoupper(Str::random(8)),
                'response_code' => 'MOCK_DECLINED',
                'response_payload' => [
                    'message' => 'Simulated card/payment decline',
                    'reason' => 'insufficient_funds',
                ],
            ];
        }

        if (! empty($metadata['simulate_async'])) {
            return [
                'status' => Payment::STATUS_PROCESSING,
                'provider_reference' => 'MOCK-ASYNC-'.strtoupper(Str::random(8)),
                'response_code' => 'MOCK_PENDING',
                'response_payload' => [
                    'message' => 'Awaiting user QRIS scan or wallet authorization',
                    'qr_string' => '00020101021226...MOCK_QRIS...',
                ],
            ];
        }

        return [
            'status' => Payment::STATUS_SUCCEEDED,
            'provider_reference' => 'MOCK-SUCC-'.strtoupper(Str::random(8)),
            'response_code' => 'MOCK_APPROVED',
            'response_payload' => [
                'message' => 'Payment approved immediately',
                'auth_code' => '123456',
            ],
        ];
    }

    public function queryPayment(Payment $payment, array $options = []): array
    {
        return [
            'status' => $payment->status,
            'provider_reference' => $payment->provider_payment_id,
            'response_code' => 'MOCK_QUERY_OK',
            'response_payload' => ['queried_at' => now()->toIso8601String()],
        ];
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $signature = $request->header('X-Signature') ?? $request->header('X-Mock-Signature');
        if (! $signature) {
            return false;
        }

        $secret = config('services.mock_payment.webhook_secret', 'mock-webhook-secret');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    public function parseWebhookEvent(Request $request): array
    {
        $payload = $request->json()->all();

        return [
            'external_event_id' => (string) ($payload['event_id'] ?? $payload['id'] ?? Str::uuid()->toString()),
            'provider_payment_id' => $payload['provider_payment_id'] ?? $payload['payment_id'] ?? null,
            'status' => $payload['status'] ?? Payment::STATUS_SUCCEEDED,
            'amount_minor' => array_key_exists('amount_minor', $payload) && $payload['amount_minor'] !== null ? (int) $payload['amount_minor'] : null,
            'currency' => isset($payload['currency']) ? (string) $payload['currency'] : null,
            'payload' => $payload,
        ];
    }

    public function processRefund(PaymentRefund $refund): array
    {
        return [
            'status' => PaymentRefund::STATUS_COMPLETED,
            'provider_reference' => 'MOCK-REF-'.strtoupper(Str::random(8)),
            'response_code' => 'MOCK_REFUND_APPROVED',
            'response_payload' => [
                'refunded_amount' => $refund->amount_minor,
                'refunded_at' => now()->toIso8601String(),
            ],
        ];
    }
}
