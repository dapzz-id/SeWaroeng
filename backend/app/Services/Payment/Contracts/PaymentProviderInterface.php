<?php

namespace App\Services\Payment\Contracts;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\TenantPaymentConnection;
use Illuminate\Http\Request;

interface PaymentProviderInterface
{
    public function getProviderName(): string;

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     status: string,
     *     provider_reference: ?string,
     *     response_code: ?string,
     *     response_payload: ?array<string, mixed>
     * }
     */
    public function initiatePayment(Payment $payment, array $options = []): array;

    /**
     * @param  array<string, mixed>  $options
     * @return array{
     *     status: string,
     *     provider_reference: ?string,
     *     response_code: ?string,
     *     response_payload: ?array<string, mixed>
     * }
     */
    public function queryPayment(Payment $payment, array $options = []): array;

    public function verifyWebhookSignature(Request $request): bool;

    /**
     * @return array{
     *     external_event_id: string,
     *     provider_payment_id: ?string,
     *     status: string,
     *     amount_minor: ?int,
     *     currency?: ?string,
     *     refund_data?: ?array{
     *         refund_key: ?string,
     *         refund_amount_minor: ?int,
     *         provider_refund_id: ?string,
     *         bank_confirmed_at: ?string
     *     },
     *     payload: array<string, mixed>
     * }
     */
    public function parseWebhookEvent(Request $request): array;

    /**
     * @return array{
     *     status: string,
     *     provider_reference: ?string,
     *     response_code: ?string,
     *     response_payload: ?array<string, mixed>
     * }
     */
    public function processRefund(PaymentRefund $refund): array;

    public function supportsRefund(?Payment $payment = null): bool;

    public function withConnection(?TenantPaymentConnection $connection): self;

    /**
     * Perform a safe, non-destructive connection verification.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{
     *     verified: bool,
     *     error_code: ?string,
     *     error_message: ?string,
     *     capabilities?: ?array<string, mixed>
     * }
     */
    public function verifyConnection(array $credentials, string $environment = 'sandbox'): array;

    /**
     * Extract provider merchant identifier from an incoming webhook request.
     */
    public function extractMerchantIdentifier(Request $request): ?string;
}
