<?php

namespace App\Services\WhatsApp\Providers;

use App\Models\TenantWhatsAppConnection;
use App\Services\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Services\WhatsApp\DTO\WhatsAppMessagePayload;
use App\Services\WhatsApp\DTO\WhatsAppSendResult;
use App\Services\WhatsApp\DTO\WhatsAppWebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class MockWhatsAppProvider implements WhatsAppProviderInterface
{
    /**
     * Runtime override for testing.
     */
    public static ?string $scenarioOverride = null;

    /**
     * Recorded messages sent via mock.
     *
     * @var array<int, array{connection: TenantWhatsAppConnection, payload: WhatsAppMessagePayload}>
     */
    public static array $sentMessages = [];

    public static function reset(): void
    {
        self::$scenarioOverride = null;
        self::$sentMessages = [];
    }

    public function getProviderName(): string
    {
        return 'mock';
    }

    public function sendMessage(TenantWhatsAppConnection $connection, WhatsAppMessagePayload $payload): WhatsAppSendResult
    {
        self::$sentMessages[] = [
            'connection' => $connection,
            'payload' => $payload,
        ];

        $scenario = self::$scenarioOverride ?? ($connection->configuration['mock_scenario'] ?? 'success');

        return match ($scenario) {
            'retryable_rate_limit' => WhatsAppSendResult::failure(
                errorCode: 'RATE_LIMIT_EXCEEDED',
                errorMessage: 'Too many requests to WhatsApp provider. Retry after backoff.',
                isRetryable: true,
                rawResponse: ['http_status' => 429, 'code' => 'RATE_LIMIT']
            ),
            'retryable_server_error' => WhatsAppSendResult::failure(
                errorCode: 'PROVIDER_INTERNAL_ERROR',
                errorMessage: 'Temporary WhatsApp server error 500.',
                isRetryable: true,
                rawResponse: ['http_status' => 500]
            ),
            'ambiguous_timeout' => WhatsAppSendResult::failure(
                errorCode: 'AMBIGUOUS_TIMEOUT',
                errorMessage: 'Connection timed out while contacting WhatsApp provider.',
                isRetryable: true,
                rawResponse: ['http_status' => 504]
            ),
            'terminal_invalid_phone' => WhatsAppSendResult::failure(
                errorCode: 'INVALID_RECIPIENT_PHONE',
                errorMessage: 'Recipient phone number is not registered on WhatsApp.',
                isRetryable: false,
                rawResponse: ['http_status' => 400, 'code' => 'INVALID_RECIPIENT']
            ),
            'terminal_unapproved_template' => WhatsAppSendResult::failure(
                errorCode: 'TEMPLATE_NOT_FOUND',
                errorMessage: 'Message template has not been approved by WhatsApp/Meta.',
                isRetryable: false,
                rawResponse: ['http_status' => 400, 'code' => 'TEMPLATE_NOT_FOUND']
            ),
            default => WhatsAppSendResult::success(
                providerMessageId: 'mock-wamid-'.(string) Str::uuid(),
                rawResponse: [
                    'messaging_product' => 'whatsapp',
                    'contacts' => [['input' => $payload->recipientPhone, 'wa_id' => ltrim($payload->recipientPhone, '+')]],
                    'messages' => [['id' => 'mock-wamid-'.(string) Str::uuid()]],
                ]
            ),
        };
    }

    public function verifyConnection(array $credentials, ?string $phoneNumberId = null, string $environment = 'sandbox'): array
    {
        $token = $credentials['access_token'] ?? $credentials['api_key'] ?? null;

        if (empty($token) || $token === 'invalid_token') {
            return [
                'verified' => false,
                'error_code' => 'INVALID_CREDENTIALS',
                'error_message' => 'The provided WhatsApp mock credentials are invalid.',
            ];
        }

        return [
            'verified' => true,
            'error_code' => null,
            'error_message' => null,
            'capabilities' => [
                'template_messages' => true,
                'text_messages' => true,
                'delivery_status_webhooks' => true,
            ],
        ];
    }

    public function verifyWebhookSignature(Request $request, TenantWhatsAppConnection $connection): bool
    {
        $expectedToken = $connection->encrypted_credentials['webhook_verify_token'] ?? 'mock_verify_token';

        // Meta challenge mode
        if ($request->query('hub_mode') === 'subscribe' || $request->query('hub.mode') === 'subscribe') {
            $token = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');

            return ! empty($token) && hash_equals($expectedToken, (string) $token);
        }

        // POST signature
        $signature = $request->header('X-Mock-Signature') ?? $request->header('X-Hub-Signature-256');
        if (empty($signature)) {
            return false;
        }

        if ($signature === 'invalid-signature') {
            return false;
        }

        return true;
    }

    public function parseWebhookEvents(Request $request): array
    {
        $statuses = $request->input('statuses', []);
        $events = [];

        foreach ($statuses as $item) {
            if (! isset($item['id'], $item['status'])) {
                continue;
            }

            $events[] = new WhatsAppWebhookEvent(
                providerMessageId: (string) $item['id'],
                status: (string) $item['status'],
                timestamp: isset($item['timestamp']) ? (string) $item['timestamp'] : null,
                recipientPhone: isset($item['recipient_id']) ? (string) $item['recipient_id'] : null,
                errorCode: $item['errors'][0]['code'] ?? null,
                errorMessage: $item['errors'][0]['title'] ?? null,
                rawPayload: $item
            );
        }

        return $events;
    }
}
