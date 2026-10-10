<?php

namespace App\Services\Payment\Providers;

use App\Exceptions\PaymentRefundNotSupportedException;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\TenantPaymentConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class DuitkuPaymentProvider extends AbstractPaymentProvider
{
    public const PROVIDER_NAME = 'duitku';

    public const CREATE_INVOICE_SANDBOX_URL = 'https://api-sandbox.duitku.com/api/merchant/createInvoice';

    public const CREATE_INVOICE_PRODUCTION_URL = 'https://api-prod.duitku.com/api/merchant/createInvoice';

    public const STATUS_SANDBOX_URL = 'https://sandbox.duitku.com/webapi/api/merchant/transactionStatus';

    public const STATUS_PRODUCTION_URL = 'https://passport.duitku.com/webapi/api/merchant/transactionStatus';

    public const PAYMENT_METHOD_SANDBOX_URL = 'https://sandbox.duitku.com/webapi/api/merchant/paymentmethod/getpaymentmethod';

    public const PAYMENT_METHOD_PRODUCTION_URL = 'https://passport.duitku.com/webapi/api/merchant/paymentmethod/getpaymentmethod';

    protected string $merchantCode;

    protected string $apiKey;

    protected bool $isProduction;

    protected int $expiryPeriodMinutes;

    public function __construct(
        ?string $merchantCode = null,
        ?string $apiKey = null,
        ?bool $isProduction = null,
        int $expiryPeriodMinutes = 1440
    ) {
        $this->merchantCode = $merchantCode ?? (string) config('services.duitku.merchant_code', '');
        $this->apiKey = $apiKey ?? (string) config('services.duitku.api_key', '');
        $this->isProduction = $isProduction ?? (bool) config('services.duitku.is_production', false);
        $this->expiryPeriodMinutes = $expiryPeriodMinutes;
    }

    public function getProviderName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function isProduction(): bool
    {
        return $this->isProduction;
    }

    public function withConnection(?TenantPaymentConnection $connection): self
    {
        $clone = clone $this;
        $clone->connection = $connection;

        if ($connection) {
            $clone->merchantCode = (string) $connection->merchant_identifier;
            $creds = $connection->encrypted_credentials ?? [];
            if (! empty($creds['api_key'])) {
                $clone->apiKey = (string) $creds['api_key'];
            }
            $clone->isProduction = ($connection->environment === 'production');
            if (isset($connection->configuration['expiry_period_minutes'])) {
                $clone->expiryPeriodMinutes = (int) $connection->configuration['expiry_period_minutes'];
            }
        }

        return $clone;
    }

    /**
     * Map internal payment to Duitku Create Invoice API.
     * Uses header-based HMAC-SHA256 signature authentication.
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
        $amount = (int) $payment->amount_minor;
        $merchantOrderId = (string) $payment->reference_number;
        $timestamp = (string) (int) (microtime(true) * 1000);
        $signature = hash_hmac('sha256', $this->merchantCode.$timestamp, $this->apiKey);

        $createUrl = $this->isProduction ? self::CREATE_INVOICE_PRODUCTION_URL : self::CREATE_INVOICE_SANDBOX_URL;

        // Duitku payment code selection (e.g. SP = ShopeePay QRIS, NQ = Nobu QRIS, BC = BCA VA)
        $paymentMethod = $options['payment_method'] ?? ($options['payment_code'] ?? null);
        if (! $paymentMethod && $payment->method === Payment::METHOD_QRIS) {
            $paymentMethod = 'SP'; // Default ShopeePay/QRIS
        }

        $payload = [
            'paymentAmount' => $amount,
            'merchantOrderId' => $merchantOrderId,
            'productDetails' => 'Order '.$merchantOrderId,
            'email' => $options['customer_email'] ?? 'customer@sewaroeng.com',
            'phoneNumber' => $options['customer_phone'] ?? null,
            'callbackUrl' => url('/api/v1/payments/webhooks/duitku'),
            'returnUrl' => url('/'),
            'expiryPeriod' => $this->expiryPeriodMinutes,
        ];

        if ($paymentMethod) {
            $payload['paymentMethod'] = (string) $paymentMethod;
        }

        try {
            $response = Http::withHeaders([
                'x-duitku-timestamp' => $timestamp,
                'x-duitku-signature' => $signature,
                'x-duitku-merchantcode' => $this->merchantCode,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])->timeout(15)->post($createUrl, $payload);

            $data = $response->json() ?? [];
            $statusCode = (string) ($data['statusCode'] ?? '');

            if ($response->successful() && $statusCode === '00') {
                $reference = $data['reference'] ?? null;
                $expiresAt = now()->addMinutes($this->expiryPeriodMinutes)->toIso8601String();

                return [
                    'status' => Payment::STATUS_PROCESSING,
                    'provider_reference' => $reference,
                    'response_code' => 'DUITKU_INVOICE_CREATED',
                    'response_payload' => [
                        'payment_url' => $data['paymentUrl'] ?? null,
                        'reference' => $reference,
                        'va_number' => $data['vaNumber'] ?? null,
                        'qr_string' => $data['qrString'] ?? null,
                        'app_url' => $data['appUrl'] ?? null,
                        'status_code' => $statusCode,
                        'status_message' => $data['statusMessage'] ?? 'SUCCESS',
                        'expires_at' => $expiresAt,
                    ],
                ];
            }

            return [
                'status' => Payment::STATUS_FAILED,
                'provider_reference' => $data['reference'] ?? null,
                'response_code' => $statusCode ?: 'DUITKU_API_REJECTED',
                'response_payload' => [
                    'status_code' => $statusCode,
                    'status_message' => $data['statusMessage'] ?? 'Failed to create invoice with Duitku.',
                    'http_status' => $response->status(),
                ],
            ];
        } catch (ConnectionException $e) {
            // Ambiguous network outcome: retain processing state and existing merchantOrderId
            return [
                'status' => Payment::STATUS_PROCESSING,
                'provider_reference' => null,
                'response_code' => 'NETWORK_TIMEOUT',
                'response_payload' => [
                    'error' => 'Network timeout while dispatching Create Invoice to Duitku.',
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status' => Payment::STATUS_FAILED,
                'provider_reference' => null,
                'response_code' => 'INITIATION_EXCEPTION',
                'response_payload' => [
                    'error' => 'Unexpected exception during Duitku invoice dispatch.',
                ],
            ];
        }
    }

    /**
     * Query transaction status from Duitku.
     * Request signature: HMAC_SHA256(merchantCode + merchantOrderId, apiKey)
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
        $merchantOrderId = (string) $payment->reference_number;
        $signature = hash_hmac('sha256', $this->merchantCode.$merchantOrderId, $this->apiKey);

        $statusUrl = $this->isProduction ? self::STATUS_PRODUCTION_URL : self::STATUS_SANDBOX_URL;

        try {
            $response = Http::asJson()
                ->timeout(10)
                ->post($statusUrl, [
                    'merchantCode' => $this->merchantCode,
                    'merchantOrderId' => $merchantOrderId,
                    'signature' => $signature,
                ]);

            if (! $response->successful()) {
                return [
                    'status' => $payment->status,
                    'provider_reference' => $payment->provider_payment_id,
                    'response_code' => 'STATUS_QUERY_HTTP_'.$response->status(),
                    'response_payload' => [
                        'http_status' => $response->status(),
                    ],
                ];
            }

            $data = $response->json() ?? [];
            $returnedOrderId = (string) ($data['merchantOrderId'] ?? '');
            $returnedAmount = isset($data['amount']) ? (int) $data['amount'] : null;
            $statusCode = (string) ($data['statusCode'] ?? '');

            // Cross-validation of returned reference and amount
            if ($returnedOrderId !== '' && $returnedOrderId !== $merchantOrderId) {
                return [
                    'status' => $payment->status,
                    'provider_reference' => $payment->provider_payment_id,
                    'response_code' => 'STATUS_QUERY_ORDER_ID_MISMATCH',
                    'response_payload' => [
                        'expected_order_id' => $merchantOrderId,
                        'returned_order_id' => $returnedOrderId,
                    ],
                ];
            }

            if ($returnedAmount !== null && $returnedAmount !== (int) $payment->amount_minor) {
                return [
                    'status' => $payment->status,
                    'provider_reference' => $payment->provider_payment_id,
                    'response_code' => 'STATUS_QUERY_AMOUNT_MISMATCH',
                    'response_payload' => [
                        'expected_amount' => $payment->amount_minor,
                        'returned_amount' => $returnedAmount,
                    ],
                ];
            }

            // Map Duitku status codes: 00 = Success, 01 = Pending, 02 = Canceled/Failed
            $mappedStatus = match ($statusCode) {
                '00' => Payment::STATUS_SUCCEEDED,
                '01' => Payment::STATUS_PROCESSING,
                '02' => Payment::STATUS_FAILED,
                default => Payment::STATUS_PROCESSING,
            };

            return [
                'status' => $mappedStatus,
                'provider_reference' => $data['reference'] ?? $payment->provider_payment_id,
                'response_code' => 'STATUS_QUERY_'.$statusCode,
                'response_payload' => [
                    'status_code' => $statusCode,
                    'status_message' => $data['statusMessage'] ?? null,
                    'reference' => $data['reference'] ?? null,
                ],
            ];
        } catch (ConnectionException $e) {
            return [
                'status' => $payment->status,
                'provider_reference' => $payment->provider_payment_id,
                'response_code' => 'STATUS_QUERY_TIMEOUT',
                'response_payload' => [
                    'error' => 'Network timeout while querying Duitku transaction status.',
                ],
            ];
        } catch (Throwable $e) {
            return [
                'status' => $payment->status,
                'provider_reference' => $payment->provider_payment_id,
                'response_code' => 'STATUS_QUERY_ERROR',
                'response_payload' => [
                    'error' => 'Unexpected error querying Duitku status.',
                ],
            ];
        }
    }

    /**
     * Verify Duitku Callback signature.
     * Formula: HMAC_SHA256(merchantCode + amount + merchantOrderId, apiKey)
     */
    public function verifyWebhookSignature(Request $request): bool
    {
        $merchantCode = (string) $request->input('merchantCode', '');
        $amount = (string) $request->input('amount', '');
        $merchantOrderId = (string) $request->input('merchantOrderId', '');
        $signature = (string) $request->input('signature', '');

        if ($merchantCode === '' || $amount === '' || $merchantOrderId === '' || $signature === '') {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $merchantCode.$amount.$merchantOrderId, $this->apiKey);

        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Parse Duitku webhook callback event.
     */
    public function parseWebhookEvent(Request $request): array
    {
        $payload = $request->all();

        $merchantOrderId = (string) ($payload['merchantOrderId'] ?? '');
        $reference = (string) ($payload['reference'] ?? '');
        $resultCode = (string) ($payload['resultCode'] ?? '');
        $amount = isset($payload['amount']) ? (int) $payload['amount'] : null;

        // Deterministic event id for deduplication
        $externalEventId = ($reference ?: $merchantOrderId).'-'.$resultCode;

        $status = match ($resultCode) {
            '00' => Payment::STATUS_SUCCEEDED,
            '01' => Payment::STATUS_PROCESSING,
            '02' => Payment::STATUS_FAILED,
            default => Payment::STATUS_PROCESSING,
        };

        return [
            'external_event_id' => $externalEventId,
            'provider_payment_id' => $merchantOrderId,
            'status' => $status,
            'amount_minor' => $amount,
            'currency' => 'IDR',
            'payload' => $payload,
        ];
    }

    public function supportsRefund(?Payment $payment = null): bool
    {
        // Standard Duitku POP does not support direct automated refund API without SNAP
        return (bool) ($this->connection?->capability_status['refund'] ?? false);
    }

    public function processRefund(PaymentRefund $refund): array
    {
        if (! $this->supportsRefund()) {
            throw new PaymentRefundNotSupportedException(
                'Duitku POP integration does not support direct automated API refunds without SNAP debit-refund capabilities.'
            );
        }

        return [
            'status' => PaymentRefund::STATUS_FAILED,
            'provider_reference' => null,
            'response_code' => 'REFUND_NOT_IMPLEMENTED',
            'response_payload' => [],
        ];
    }

    /**
     * Non-destructive safe connection verification using Get Payment Method API.
     * Signature: HMAC_SHA256(merchantCode + amount + datetime, apiKey)
     */
    public function verifyConnection(array $credentials, string $environment = 'sandbox'): array
    {
        $merchantCode = (string) ($credentials['merchant_code'] ?? $this->merchantCode);
        $apiKey = (string) ($credentials['api_key'] ?? $this->apiKey);

        if (empty($merchantCode) || empty($apiKey)) {
            return [
                'verified' => false,
                'error_code' => 'MISSING_CREDENTIALS',
                'error_message' => 'Merchant Code and API Key are required for Duitku.',
            ];
        }

        $datetime = Carbon::now()->format('Y-m-d H:i:s');
        $amount = 10000;
        $signature = hash_hmac('sha256', $merchantCode.$amount.$datetime, $apiKey);

        $endpoint = $environment === 'production'
            ? self::PAYMENT_METHOD_PRODUCTION_URL
            : self::PAYMENT_METHOD_SANDBOX_URL;

        try {
            $response = Http::asJson()
                ->timeout(10)
                ->post($endpoint, [
                    'merchantCode' => $merchantCode,
                    'amount' => $amount,
                    'datetime' => $datetime,
                    'signature' => $signature,
                ]);

            if ($response->successful()) {
                $data = $response->json() ?? [];
                // Check if response contains paymentFee list or valid code
                $methods = [];
                if (isset($data['paymentFee']) && is_array($data['paymentFee'])) {
                    foreach ($data['paymentFee'] as $item) {
                        if (isset($item['paymentMethod'])) {
                            $methods[] = (string) $item['paymentMethod'];
                        }
                    }
                }

                return [
                    'verified' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'capabilities' => [
                        'supported_methods' => $methods ?: ['qris', 'bank_transfer', 'e_wallet', 'retail'],
                        'refund' => false,
                        'status_inquiry' => true,
                    ],
                ];
            }

            return [
                'verified' => false,
                'error_code' => 'VERIFICATION_HTTP_'.$response->status(),
                'error_message' => 'Duitku verification rejected (HTTP '.$response->status().').',
            ];
        } catch (ConnectionException $e) {
            return [
                'verified' => false,
                'error_code' => 'CONNECTION_TIMEOUT',
                'error_message' => 'Network timeout connecting to Duitku payment method endpoint.',
            ];
        } catch (Throwable $e) {
            return [
                'verified' => false,
                'error_code' => 'VERIFICATION_EXCEPTION',
                'error_message' => 'Unexpected error during Duitku connection verification.',
            ];
        }
    }

    public function extractMerchantIdentifier(Request $request): ?string
    {
        return $request->filled('merchantCode') ? (string) $request->input('merchantCode') : null;
    }
}
