<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\TenantPaymentConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

class MidtransPaymentProvider extends AbstractPaymentProvider
{
    public const PROVIDER_NAME = 'midtrans';

    public const SANDBOX_URL = 'https://api.sandbox.midtrans.com';

    public const PRODUCTION_URL = 'https://api.midtrans.com';

    protected string $serverKey;

    protected string $clientKey;

    protected string $merchantId;

    protected bool $isProduction;

    protected string $baseUrl;

    public function __construct(
        ?string $serverKey = null,
        ?string $clientKey = null,
        ?string $merchantId = null,
        ?bool $isProduction = null,
        ?string $baseUrl = null,
    ) {
        $this->serverKey = $serverKey ?? (string) config('services.midtrans.server_key', '');
        $this->clientKey = $clientKey ?? (string) config('services.midtrans.client_key', '');
        $this->merchantId = $merchantId ?? (string) config('services.midtrans.merchant_id', '');
        $this->isProduction = $isProduction ?? (bool) config('services.midtrans.is_production', false);
        $this->baseUrl = $baseUrl ?? config('services.midtrans.base_url') ?? ($this->isProduction ? self::PRODUCTION_URL : self::SANDBOX_URL);
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function isProduction(): bool
    {
        return $this->isProduction;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Initiate payment charge with Midtrans Core API.
     * Supports QRIS (GoPay/ShopeePay), Bank Transfer (VA), and e-Wallets.
     *
     * @param  array<string, mixed>  $options
     * @return array{
     *     status: string,
     *     provider_reference: ?string,
     *     response_code: ?string,
     *     response_payload: ?array<string, mixed>
     * }
     */
    public function initiatePayment(Payment $payment, array $options = []): array
    {
        // Guard 1: Currency validation (Midtrans IDR rails only)
        if ($payment->currency !== Payment::CURRENCY_IDR) {
            return [
                'status' => Payment::STATUS_FAILED,
                'provider_reference' => null,
                'response_code' => 'UNSUPPORTED_CURRENCY',
                'response_payload' => [
                    'reason' => "Midtrans provider only supports IDR currency, received '{$payment->currency}'.",
                ],
            ];
        }

        // Guard 2: Amount validation
        if ($payment->amount_minor <= 0) {
            return [
                'status' => Payment::STATUS_FAILED,
                'provider_reference' => null,
                'response_code' => 'INVALID_AMOUNT',
                'response_payload' => [
                    'reason' => 'Payment amount must be greater than zero.',
                ],
            ];
        }

        // Resolve Midtrans payment_type based on options or Payment method
        $paymentType = $this->resolvePaymentType($payment, $options);

        // Build authoritative request payload
        $payload = [
            'payment_type' => $paymentType,
            'transaction_details' => [
                'order_id' => $payment->reference_number,
                'gross_amount' => $payment->amount_minor,
            ],
        ];

        if ($paymentType === 'qris') {
            $payload['qris'] = [
                'acquirer' => $options['acquirer'] ?? 'gopay',
            ];
        } elseif ($paymentType === 'bank_transfer') {
            $payload['bank_transfer'] = [
                'bank' => $options['bank'] ?? 'bca',
            ];
        }

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(15)
                ->post("{$this->baseUrl}/v2/charge", $payload);
        } catch (ConnectionException $e) {
            // Section 18: Distinguish network timeout from provider rejection.
            // Outcome unknown -> return processing to prevent duplicate charging.
            return [
                'status' => Payment::STATUS_PROCESSING,
                'provider_reference' => null,
                'response_code' => 'NETWORK_TIMEOUT',
                'response_payload' => [
                    'error' => 'Connection timed out while contacting Midtrans gateway. Preserving processing state for status inquiry.',
                    'exception' => $e->getMessage(),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status' => Payment::STATUS_PROCESSING,
                'provider_reference' => null,
                'response_code' => 'GATEWAY_ERROR',
                'response_payload' => [
                    'error' => 'Gateway error during payment initiation: '.$e->getMessage(),
                ],
            ];
        }

        $statusCode = $response->status();
        $body = $response->json() ?? [];

        // 5xx Server Error from gateway -> outcome unknown, preserve processing
        if ($statusCode >= 500) {
            return [
                'status' => Payment::STATUS_PROCESSING,
                'provider_reference' => null,
                'response_code' => 'GATEWAY_5XX',
                'response_payload' => [
                    'error' => "Gateway returned HTTP {$statusCode}",
                    'body' => $body,
                ],
            ];
        }

        // 201 Created (Standard async QRIS/VA pending creation)
        if ($statusCode === 201) {
            $transactionStatus = $body['transaction_status'] ?? 'pending';
            $internalStatus = $this->mapTransactionStatus($transactionStatus, $body['fraud_status'] ?? 'accept');

            return [
                'status' => $internalStatus,
                'provider_reference' => $body['transaction_id'] ?? $payment->reference_number,
                'response_code' => $body['status_code'] ?? '201',
                'response_payload' => $this->sanitizeResponsePayload($body),
            ];
        }

        // 200 OK (Synchronous settlement or capture)
        if ($statusCode === 200) {
            $transactionStatus = $body['transaction_status'] ?? 'settlement';
            $internalStatus = $this->mapTransactionStatus($transactionStatus, $body['fraud_status'] ?? 'accept');

            return [
                'status' => $internalStatus,
                'provider_reference' => $body['transaction_id'] ?? $payment->reference_number,
                'response_code' => $body['status_code'] ?? '200',
                'response_payload' => $this->sanitizeResponsePayload($body),
            ];
        }

        // 4xx Client Error / Gateway Decline
        return [
            'status' => Payment::STATUS_FAILED,
            'provider_reference' => $body['transaction_id'] ?? null,
            'response_code' => $body['status_code'] ?? (string) $statusCode,
            'response_payload' => [
                'reason' => $body['status_message'] ?? 'Midtrans rejected payment initiation request',
                'validation_messages' => $body['validation_messages'] ?? null,
            ],
        ];
    }

    /**
     * Determine whether the given payment method supports direct automated refund.
     * Supported: QRIS, GoPay, ShopeePay, Card.
     * Unsupported: Bank Transfer / Virtual Accounts (requires manual Iris disbursement).
     */
    public function supportsRefund(?Payment $payment = null): bool
    {
        if ($payment === null) {
            return true;
        }

        $method = $payment->method;
        $paymentType = strtolower((string) ($payment->metadata['payment_type'] ?? ''));

        if ($method === Payment::METHOD_BANK_TRANSFER || in_array($paymentType, ['bank_transfer', 'echannel', 'permata_va', 'bca_va', 'bni_va', 'bri_va', 'cimb_va'], true)) {
            return false;
        }

        if (in_array($method, [Payment::METHOD_QRIS, Payment::METHOD_E_WALLET, Payment::METHOD_CARD], true)) {
            return true;
        }

        if (in_array($paymentType, ['qris', 'gopay', 'shopeepay', 'credit_card'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Resolves the appropriate identifier (order_id vs transaction_id) for Midtrans status query.
     * BI SNAP / DANA transactions require transaction_id, standard core API uses order_id.
     *
     * @param  array<string, mixed>  $options
     */
    public function resolveStatusIdentifier(Payment $payment, array $options = []): string
    {
        if (! empty($options['identifier'])) {
            return (string) $options['identifier'];
        }

        if (! empty($options['use_transaction_id']) && ! empty($payment->provider_payment_id)) {
            return $payment->provider_payment_id;
        }

        $paymentType = strtolower((string) ($options['payment_type'] ?? $payment->metadata['payment_type'] ?? ''));
        $isSnap = ($options['is_snap'] ?? $payment->metadata['is_snap'] ?? false) || str_starts_with($paymentType, 'snap');

        if (($paymentType === 'dana' || $isSnap) && ! empty($payment->provider_payment_id)) {
            return $payment->provider_payment_id;
        }

        return $payment->reference_number;
    }

    /**
     * Query payment status via Midtrans status inquiry API:
     * GET /v2/{identifier}/status
     *
     * @param  array<string, mixed>  $options
     * @return array{
     *     status: string,
     *     provider_reference: ?string,
     *     response_code: ?string,
     *     response_payload: ?array<string, mixed>
     * }
     */
    public function queryPayment(Payment $payment, array $options = []): array
    {
        $identifier = $this->resolveStatusIdentifier($payment, $options);

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->withHeaders(['Accept' => 'application/json'])
                ->timeout(10)
                ->get("{$this->baseUrl}/v2/{$identifier}/status");
        } catch (ConnectionException $e) {
            return [
                'status' => $payment->status,
                'provider_reference' => $payment->provider_payment_id,
                'response_code' => 'QUERY_TIMEOUT',
                'response_payload' => [
                    'error' => 'Connection timed out during status inquiry: '.$e->getMessage(),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status' => $payment->status,
                'provider_reference' => $payment->provider_payment_id,
                'response_code' => 'QUERY_ERROR',
                'response_payload' => [
                    'error' => 'Gateway error during status inquiry: '.$e->getMessage(),
                ],
            ];
        }

        $statusCode = $response->status();
        $body = $response->json() ?? [];

        if ($statusCode === 200) {
            // Validate response fields (Section 8)
            $returnedOrderId = (string) ($body['order_id'] ?? '');
            if ($returnedOrderId !== '' && $returnedOrderId !== $payment->reference_number) {
                return [
                    'status' => $payment->status,
                    'provider_reference' => $body['transaction_id'] ?? null,
                    'response_code' => 'STATUS_QUERY_ORDER_MISMATCH',
                    'response_payload' => [
                        'error' => "Returned order_id '{$returnedOrderId}' does not match expected '{$payment->reference_number}'.",
                    ],
                ];
            }

            if (isset($body['gross_amount'])) {
                $returnedAmount = (int) round((float) $body['gross_amount']);
                if ($returnedAmount !== (int) $payment->amount_minor) {
                    return [
                        'status' => $payment->status,
                        'provider_reference' => $body['transaction_id'] ?? null,
                        'response_code' => 'STATUS_QUERY_AMOUNT_MISMATCH',
                        'response_payload' => [
                            'error' => "Returned gross_amount ({$returnedAmount}) does not match expected ({$payment->amount_minor}).",
                        ],
                    ];
                }
            }

            if (isset($body['currency'])) {
                $returnedCurrency = strtoupper((string) $body['currency']);
                if ($returnedCurrency !== strtoupper($payment->currency)) {
                    return [
                        'status' => $payment->status,
                        'provider_reference' => $body['transaction_id'] ?? null,
                        'response_code' => 'STATUS_QUERY_CURRENCY_MISMATCH',
                        'response_payload' => [
                            'error' => "Returned currency '{$returnedCurrency}' does not match expected '{$payment->currency}'.",
                        ],
                    ];
                }
            }

            $transactionStatus = $body['transaction_status'] ?? '';
            $fraudStatus = $body['fraud_status'] ?? 'accept';
            $mappedStatus = $this->mapTransactionStatus($transactionStatus, $fraudStatus);

            return [
                'status' => $mappedStatus,
                'provider_reference' => $body['transaction_id'] ?? null,
                'response_code' => $body['status_code'] ?? '200',
                'response_payload' => $this->sanitizeResponsePayload($body),
            ];
        }

        if ($statusCode === 404) {
            return [
                'status' => $payment->status,
                'provider_reference' => $payment->provider_payment_id,
                'response_code' => 'TRANSACTION_NOT_FOUND',
                'response_payload' => [
                    'reason' => 'Transaction not found at Midtrans.',
                ],
            ];
        }

        return [
            'status' => $payment->status,
            'provider_reference' => $payment->provider_payment_id,
            'response_code' => (string) $statusCode,
            'response_payload' => $body,
        ];
    }

    /**
     * Verify Midtrans webhook notification signature.
     * Signature specification: SHA512(order_id + status_code + gross_amount + ServerKey).
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        if ($this->serverKey === '') {
            return false;
        }

        $signatureKey = (string) ($request->input('signature_key') ?? $request->header('X-Signature') ?? '');
        $orderId = (string) $request->input('order_id', '');
        $statusCode = (string) $request->input('status_code', '');
        $grossAmount = (string) $request->input('gross_amount', '');

        if ($signatureKey === '' || $orderId === '' || $statusCode === '' || $grossAmount === '') {
            return false;
        }

        $expected = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        return hash_equals($expected, $signatureKey);
    }

    /**
     * Parse and normalize Midtrans webhook event payload.
     *
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
    public function parseWebhookEvent(Request $request): array
    {
        $payload = $request->json()->all() ?: $request->all();

        if (empty($payload['order_id']) && empty($payload['transaction_id'])) {
            throw new InvalidArgumentException('Malformed Midtrans webhook payload: missing order_id and transaction_id.');
        }

        $orderId = (string) ($payload['order_id'] ?? '');
        $transactionId = (string) ($payload['transaction_id'] ?? '');
        $transactionStatus = (string) ($payload['transaction_status'] ?? '');
        $statusCode = (string) ($payload['status_code'] ?? '');
        $fraudStatus = (string) ($payload['fraud_status'] ?? 'accept');

        // Extract refund details if this notification contains a refund event
        $refundData = null;
        $isRefundEvent = in_array(strtolower($transactionStatus), ['refund', 'partial_refund'], true)
            || isset($payload['refund_key'])
            || isset($payload['refund_charge_id']);

        if ($isRefundEvent) {
            $refundKey = (string) ($payload['refund_key'] ?? '');
            $refundAmountMinor = isset($payload['refund_amount']) ? (int) round((float) $payload['refund_amount']) : null;
            $bankConfirmedAt = $payload['bank_confirmed_at'] ?? null;
            $providerRefundId = (string) ($payload['refund_charge_id'] ?? $payload['refund_id'] ?? '');

            // Fallback: check nested refunds array if present
            if ($refundKey === '' && ! empty($payload['refunds']) && is_array($payload['refunds'])) {
                $latestRefund = end($payload['refunds']);
                if (is_array($latestRefund)) {
                    $refundKey = (string) ($latestRefund['refund_key'] ?? '');
                    $refundAmountMinor = isset($latestRefund['refund_amount']) ? (int) round((float) $latestRefund['refund_amount']) : $refundAmountMinor;
                    $bankConfirmedAt = $latestRefund['bank_confirmed_at'] ?? $bankConfirmedAt;
                    $providerRefundId = (string) ($latestRefund['refund_charge_id'] ?? $providerRefundId);
                }
            }

            $refundData = [
                'refund_key' => $refundKey !== '' ? $refundKey : null,
                'refund_amount_minor' => $refundAmountMinor,
                'provider_refund_id' => $providerRefundId !== '' ? $providerRefundId : null,
                'bank_confirmed_at' => $bankConfirmedAt,
            ];
        }

        // Deterministic event ID identifying the state transition for idempotency
        $refundKeySuffix = ($refundData && $refundData['refund_key']) ? "-{$refundData['refund_key']}" : '';
        $eventId = (string) ($payload['id_event'] ?? "{$transactionId}-{$statusCode}-{$transactionStatus}{$refundKeySuffix}");

        $status = $this->mapTransactionStatus($transactionStatus, $fraudStatus);

        $amountMinor = null;
        if (isset($payload['gross_amount'])) {
            $amountMinor = (int) round((float) $payload['gross_amount']);
        }

        $currency = isset($payload['currency']) ? strtoupper((string) $payload['currency']) : Payment::CURRENCY_IDR;

        return [
            'external_event_id' => $eventId,
            'provider_payment_id' => $orderId !== '' ? $orderId : $transactionId,
            'status' => $status,
            'amount_minor' => $amountMinor,
            'currency' => $currency,
            'refund_data' => $refundData,
            'payload' => $payload,
        ];
    }

    /**
     * Process refund via Midtrans Direct Refund API:
     * POST /v2/{order_id}/refund
     *
     * @return array{
     *     status: string,
     *     provider_reference: ?string,
     *     response_code: ?string,
     *     bank_confirmed_at?: ?string,
     *     response_payload: ?array<string, mixed>
     * }
     */
    public function processRefund(PaymentRefund $refund): array
    {
        if (! $this->supportsRefund($refund->payment)) {
            return [
                'status' => PaymentRefund::STATUS_FAILED,
                'provider_reference' => null,
                'response_code' => 'PAYMENT_REFUND_NOT_SUPPORTED',
                'response_payload' => [
                    'reason' => 'Midtrans does not support automated direct refunds for Bank Transfer / Virtual Accounts.',
                ],
            ];
        }

        $orderId = $refund->payment->reference_number;

        $payload = [
            'refund_key' => $refund->reference_number,
            'amount' => $refund->amount_minor,
            'reason' => $refund->reason,
        ];

        try {
            $response = Http::withBasicAuth($this->serverKey, '')
                ->withHeaders([
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ])
                ->timeout(15)
                ->post("{$this->baseUrl}/v2/{$orderId}/refund", $payload);
        } catch (ConnectionException $e) {
            // Network timeout: internal result unknown -> preserve processing without new key
            return [
                'status' => PaymentRefund::STATUS_PROCESSING,
                'provider_reference' => null,
                'response_code' => 'REFUND_NETWORK_TIMEOUT',
                'response_payload' => [
                    'error' => 'Connection timed out during refund request. State remains processing to preserve idempotency key: '.$e->getMessage(),
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status' => PaymentRefund::STATUS_FAILED,
                'provider_reference' => null,
                'response_code' => 'REFUND_GATEWAY_ERROR',
                'response_payload' => [
                    'error' => 'Gateway error during refund request: '.$e->getMessage(),
                ],
            ];
        }

        $statusCode = $response->status();
        $body = $response->json() ?? [];

        if ($statusCode === 200) {
            // Midtrans HTTP 200 acceptance means the refund request is accepted for processing.
            // It is only marked financially completed if bank_confirmed_at is returned immediately.
            $bankConfirmedAt = $body['bank_confirmed_at'] ?? null;
            $refundStatus = $bankConfirmedAt ? PaymentRefund::STATUS_COMPLETED : PaymentRefund::STATUS_PROCESSING;

            return [
                'status' => $refundStatus,
                'provider_reference' => $body['refund_charge_id'] ?? $body['refund_key'] ?? $refund->reference_number,
                'response_code' => $body['status_code'] ?? '200',
                'bank_confirmed_at' => $bankConfirmedAt,
                'response_payload' => $this->sanitizeResponsePayload($body),
            ];
        }

        return [
            'status' => PaymentRefund::STATUS_FAILED,
            'provider_reference' => null,
            'response_code' => $body['status_code'] ?? (string) $statusCode,
            'response_payload' => [
                'reason' => $body['status_message'] ?? 'Midtrans rejected refund request',
            ],
        ];
    }

    /**
     * Maps Midtrans transaction_status and fraud_status to internal Payment status.
     */
    public function mapTransactionStatus(string $transactionStatus, string $fraudStatus = 'accept'): string
    {
        return match (strtolower(trim($transactionStatus))) {
            'settlement' => Payment::STATUS_SUCCEEDED,
            'capture' => strtolower(trim($fraudStatus)) === 'challenge'
                ? Payment::STATUS_PROCESSING
                : Payment::STATUS_SUCCEEDED,
            'pending' => Payment::STATUS_PROCESSING,
            'deny' => Payment::STATUS_FAILED,
            'cancel' => Payment::STATUS_CANCELLED,
            'expire' => Payment::STATUS_FAILED,
            'refund' => Payment::STATUS_REFUNDED,
            'partial_refund' => Payment::STATUS_PARTIALLY_REFUNDED,
            default => Payment::STATUS_PROCESSING,
        };
    }

    /**
     * Resolves Midtrans payment_type string from Payment method and options.
     *
     * @param  array<string, mixed>  $options
     */
    protected function resolvePaymentType(Payment $payment, array $options): string
    {
        if (! empty($options['payment_type'])) {
            return (string) $options['payment_type'];
        }

        return match ($payment->method) {
            Payment::METHOD_QRIS => 'qris',
            Payment::METHOD_BANK_TRANSFER => 'bank_transfer',
            Payment::METHOD_E_WALLET => 'gopay',
            default => 'qris',
        };
    }

    /**
     * Extracts only client-safe metadata without exposing internal gateway secrets or tokens.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function sanitizeResponsePayload(array $body): array
    {
        $safeKeys = [
            'transaction_id',
            'order_id',
            'gross_amount',
            'currency',
            'payment_type',
            'transaction_status',
            'status_code',
            'status_message',
            'qr_string',
            'actions',
            'va_numbers',
            'bca_va_number',
            'permata_va_number',
            'bill_key',
            'biller_code',
            'expiry_time',
            'settlement_time',
            'refund_key',
            'refund_charge_id',
            'refund_amount',
            'bank_confirmed_at',
            'created_at',
            'refunds',
        ];

        $result = [];
        foreach ($safeKeys as $key) {
            if (array_key_exists($key, $body)) {
                $result[$key] = $body[$key];
            }
        }

        return $result;
    }

    public function withConnection(?TenantPaymentConnection $connection): self
    {
        $clone = clone $this;
        $clone->connection = $connection;

        if ($connection) {
            $creds = $connection->encrypted_credentials ?? [];
            if (! empty($creds['server_key'])) {
                $clone->serverKey = (string) $creds['server_key'];
            }
            if (! empty($creds['client_key'])) {
                $clone->clientKey = (string) $creds['client_key'];
            }
            if (! empty($connection->merchant_identifier)) {
                $clone->merchantId = (string) $connection->merchant_identifier;
            }
            $clone->isProduction = ($connection->environment === 'production');
            $clone->baseUrl = $clone->isProduction ? self::PRODUCTION_URL : self::SANDBOX_URL;
        }

        return $clone;
    }

    public function verifyConnection(array $credentials, string $environment = 'sandbox'): array
    {
        $serverKey = (string) ($credentials['server_key'] ?? '');
        if (empty($serverKey)) {
            return [
                'verified' => false,
                'error_code' => 'MISSING_SERVER_KEY',
                'error_message' => 'Midtrans Server Key is required.',
            ];
        }

        $baseUrl = $environment === 'production' ? self::PRODUCTION_URL : self::SANDBOX_URL;

        try {
            // Non-destructive check: Query dummy status endpoint.
            // HTTP 404 (Transaction not found) proves valid serverKey authentication.
            // HTTP 401 (Access denied) proves invalid serverKey.
            $response = Http::withHeaders([
                'Authorization' => 'Basic '.base64_encode($serverKey.':'),
                'Accept' => 'application/json',
            ])->timeout(10)->get("{$baseUrl}/v2/sewaroeng-verify-ping/status");

            if ($response->status() === 401 || $response->status() === 403) {
                return [
                    'verified' => false,
                    'error_code' => 'INVALID_CREDENTIALS',
                    'error_message' => 'Midtrans returned authentication failure (HTTP '.$response->status().').',
                ];
            }

            return [
                'verified' => true,
                'error_code' => null,
                'error_message' => null,
                'capabilities' => [
                    'supported_methods' => ['qris', 'bank_transfer', 'card', 'e_wallet'],
                    'refund' => true,
                    'status_inquiry' => true,
                ],
            ];
        } catch (ConnectionException $e) {
            return [
                'verified' => false,
                'error_code' => 'CONNECTION_TIMEOUT',
                'error_message' => 'Network timeout connecting to Midtrans API.',
            ];
        } catch (Throwable $e) {
            return [
                'verified' => false,
                'error_code' => 'VERIFICATION_ERROR',
                'error_message' => 'Unexpected error during verification.',
            ];
        }
    }

    public function extractMerchantIdentifier(Request $request): ?string
    {
        return $request->filled('merchant_id') ? (string) $request->input('merchant_id') : null;
    }
}
