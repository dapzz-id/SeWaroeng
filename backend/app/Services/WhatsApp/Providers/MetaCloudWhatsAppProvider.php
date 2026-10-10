<?php

namespace App\Services\WhatsApp\Providers;

use App\Models\TenantWhatsAppConnection;
use App\Services\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Services\WhatsApp\DTO\WhatsAppMessagePayload;
use App\Services\WhatsApp\DTO\WhatsAppSendResult;
use App\Services\WhatsApp\DTO\WhatsAppWebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaCloudWhatsAppProvider implements WhatsAppProviderInterface
{
    public const DEFAULT_API_VERSION = 'v21.0';

    public const SUPPORTED_API_VERSIONS = ['v20.0', 'v21.0', 'v22.0'];

    public function getProviderName(): string
    {
        return 'meta_cloud';
    }

    /**
     * Resolve and validate server-configured Meta Graph API version.
     */
    public function resolveApiVersion(TenantWhatsAppConnection $connection): string
    {
        $configured = $connection->configuration['api_version']
            ?? config('services.whatsapp.meta.graph_version', self::DEFAULT_API_VERSION);

        if (! in_array($configured, self::SUPPORTED_API_VERSIONS, true)) {
            throw new \InvalidArgumentException("Unsupported or expired Meta Graph API version '{$configured}'. Supported versions: ".implode(', ', self::SUPPORTED_API_VERSIONS));
        }

        return $configured;
    }

    /**
     * Send message using Meta WhatsApp Cloud API.
     */
    public function sendMessage(TenantWhatsAppConnection $connection, WhatsAppMessagePayload $payload): WhatsAppSendResult
    {
        $credentials = $connection->encrypted_credentials ?? [];
        $accessToken = $credentials['access_token'] ?? null;
        $phoneNumberId = $connection->phone_number_id;

        if (empty($accessToken) || empty($phoneNumberId)) {
            return WhatsAppSendResult::failure(
                errorCode: 'CONFIG_INCOMPLETE',
                errorMessage: 'Meta WhatsApp Cloud API credentials or Phone Number ID are missing.',
                isRetryable: false
            );
        }

        $apiVersion = $this->resolveApiVersion($connection);
        $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}/messages";

        // Template approval status contract validation
        if (! empty($payload->templateName)) {
            $templatesConfig = $connection->configuration['templates'] ?? [];
            $templateStatus = $templatesConfig[$payload->templateName]['status'] ?? 'APPROVED';

            if (strtoupper($templateStatus) === 'REJECTED') {
                return WhatsAppSendResult::failure(
                    errorCode: 'TEMPLATE_REJECTED',
                    errorMessage: "Template '{$payload->templateName}' has been rejected by Meta and cannot be sent.",
                    isRetryable: false
                );
            }
        }

        // Build official Meta Cloud API payload
        $body = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $payload->recipientPhone,
        ];

        if (! empty($payload->templateName)) {
            $body['type'] = 'template';
            $languageCode = $payload->metadata['language_code'] ?? 'id';

            $components = [];
            if (! empty($payload->templateParameters)) {
                $parameters = [];
                foreach ($payload->templateParameters as $param) {
                    $parameters[] = [
                        'type' => 'text',
                        'text' => (string) $param,
                    ];
                }
                $components[] = [
                    'type' => 'body',
                    'parameters' => $parameters,
                ];
            }

            $body['template'] = [
                'name' => $payload->templateName,
                'language' => [
                    'code' => $languageCode,
                ],
                'components' => $components,
            ];
        } else {
            // Freeform text (only valid within 24hr customer service window)
            $body['type'] = 'text';
            $body['text'] = [
                'preview_url' => false,
                'body' => $payload->textBody ?? '',
            ];
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->acceptJson()
                ->post($url, $body);

            $statusCode = $response->status();
            $responseData = $response->json();

            if ($response->successful()) {
                $messageId = $responseData['messages'][0]['id'] ?? null;
                if (! $messageId) {
                    return WhatsAppSendResult::failure(
                        errorCode: 'EMPTY_PROVIDER_MESSAGE_ID',
                        errorMessage: 'Meta API succeeded but returned no message ID.',
                        isRetryable: false,
                        rawResponse: $this->sanitizeResponse($responseData)
                    );
                }

                return WhatsAppSendResult::success(
                    providerMessageId: $messageId,
                    rawResponse: $this->sanitizeResponse($responseData)
                );
            }

            // Classify errors according to official Meta error codes
            $errorObj = $responseData['error'] ?? [];
            $metaErrorCode = (string) ($errorObj['code'] ?? $statusCode);
            $metaErrorMessage = $errorObj['message'] ?? 'Unknown Meta API error';

            // Retryable: 429 (Rate Limit), 500, 502, 503, 504, or Meta subcode 130429
            $isRetryable = in_array($statusCode, [429, 500, 502, 503, 504], true)
                || in_array($metaErrorCode, ['80007', '130429', '131053'], true);

            return WhatsAppSendResult::failure(
                errorCode: "META_ERR_{$metaErrorCode}",
                errorMessage: $metaErrorMessage,
                isRetryable: $isRetryable,
                rawResponse: $this->sanitizeResponse($responseData)
            );
        } catch (ConnectionException) {
            return WhatsAppSendResult::failure(
                errorCode: 'AMBIGUOUS_TIMEOUT',
                errorMessage: 'Connection to Meta Graph API timed out. Delivery status is ambiguous and must be retried with caution.',
                isRetryable: true
            );
        } catch (\Throwable $e) {
            Log::error('Meta Cloud WhatsApp unhandled send error: sanitized exception logged', [
                'error_type' => get_class($e),
            ]);

            return WhatsAppSendResult::failure(
                errorCode: 'PROVIDER_EXCEPTION',
                errorMessage: 'An exception occurred while sending WhatsApp message.',
                isRetryable: true
            );
        }
    }

    /**
     * Verify credentials against Meta Graph API.
     */
    public function verifyConnection(array $credentials, ?string $phoneNumberId = null, string $environment = 'sandbox'): array
    {
        $accessToken = $credentials['access_token'] ?? null;

        if (empty($accessToken) || empty($phoneNumberId)) {
            return [
                'verified' => false,
                'error_code' => 'MISSING_CREDENTIALS',
                'error_message' => 'Access token and Phone Number ID are required for Meta Cloud API.',
            ];
        }

        $apiVersion = config('services.whatsapp.meta.graph_version', self::DEFAULT_API_VERSION);
        $url = "https://graph.facebook.com/{$apiVersion}/{$phoneNumberId}";

        try {
            $response = Http::withToken($accessToken)
                ->timeout(5)
                ->get($url);

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'verified' => true,
                    'error_code' => null,
                    'error_message' => null,
                    'capabilities' => [
                        'display_phone_number' => $data['display_phone_number'] ?? null,
                        'verified_name' => $data['verified_name'] ?? null,
                        'quality_rating' => $data['quality_rating'] ?? null,
                    ],
                ];
            }

            $error = $response->json('error');

            return [
                'verified' => false,
                'error_code' => 'VERIFICATION_FAILED',
                'error_message' => $error['message'] ?? 'Failed to verify Meta WhatsApp credentials.',
            ];
        } catch (\Throwable $e) {
            return [
                'verified' => false,
                'error_code' => 'NETWORK_ERROR',
                'error_message' => 'Unable to reach Meta Graph API.',
            ];
        }
    }

    /**
     * Verify Meta Webhook authenticity.
     * Supports GET subscription challenge and POST X-Hub-Signature-256 with constant-time verification.
     */
    public function verifyWebhookSignature(Request $request, TenantWhatsAppConnection $connection): bool
    {
        $credentials = $connection->encrypted_credentials ?? [];

        // 1. GET Subscription Verification Challenge
        if ($request->isMethod('GET')) {
            $mode = $request->query('hub_mode') ?? $request->query('hub.mode');
            $verifyToken = $request->query('hub_verify_token') ?? $request->query('hub.verify_token');
            $expectedToken = $credentials['webhook_verify_token']
                ?? config('services.whatsapp.meta.verify_token');

            if ($mode === 'subscribe' && ! empty($expectedToken) && hash_equals($expectedToken, (string) $verifyToken)) {
                return true;
            }

            return false;
        }

        // 2. POST Delivery Status Notification (HMAC-SHA256 signature)
        $signatureHeader = $request->header('X-Hub-Signature-256');
        $appSecret = $credentials['app_secret']
            ?? config('services.whatsapp.meta.app_secret');

        if (empty($signatureHeader) || empty($appSecret)) {
            return false;
        }

        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return false;
        }

        $expectedHash = hash_hmac('sha256', $request->getContent(), $appSecret);
        $providedHash = substr($signatureHeader, 7);

        return hash_equals($expectedHash, $providedHash);
    }

    /**
     * Parse official Meta WhatsApp Cloud API webhook statuses.
     */
    public function parseWebhookEvents(Request $request): array
    {
        $payload = $request->json()->all();
        $events = [];

        $entries = $payload['entry'] ?? [];
        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];
            foreach ($changes as $change) {
                $value = $change['value'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
                $statuses = $value['statuses'] ?? [];

                foreach ($statuses as $statusItem) {
                    $messageId = $statusItem['id'] ?? null;
                    $status = $statusItem['status'] ?? null;

                    if (! $messageId || ! $status) {
                        continue;
                    }

                    $errorCode = null;
                    $errorMessage = null;
                    if (! empty($statusItem['errors'])) {
                        $firstError = $statusItem['errors'][0];
                        $errorCode = (string) ($firstError['code'] ?? 'META_ERROR');
                        $errorMessage = $firstError['title'] ?? ($firstError['message'] ?? null);
                    }

                    $events[] = new WhatsAppWebhookEvent(
                        providerMessageId: $messageId,
                        status: $status,
                        timestamp: isset($statusItem['timestamp']) ? (string) $statusItem['timestamp'] : null,
                        recipientPhone: isset($statusItem['recipient_id']) ? '+'.$statusItem['recipient_id'] : null,
                        errorCode: $errorCode,
                        errorMessage: $errorMessage,
                        phoneNumberId: $phoneNumberId ? (string) $phoneNumberId : null,
                        rawPayload: $statusItem
                    );
                }
            }
        }

        return $events;
    }

    /**
     * Sanitize provider response before recording to audit or metadata.
     * Prevents access tokens, app secrets, and credentials from leaking.
     *
     * @param  array<string, mixed>|null  $response
     * @return array<string, mixed>|null
     */
    protected function sanitizeResponse(?array $response): ?array
    {
        if ($response === null) {
            return null;
        }

        $sensitiveKeys = ['access_token', 'token', 'auth', 'app_secret', 'secret', 'password', 'authorization'];

        array_walk_recursive($response, function (&$value, $key) use ($sensitiveKeys) {
            if (in_array(strtolower((string) $key), $sensitiveKeys, true)) {
                $value = '[REDACTED]';
            } elseif (is_string($value)) {
                $value = preg_replace('/(access_token|EAAG[A-Za-z0-9_-]+|bearer)\s*[:=]?\s*[\w\.\-]+/i', '$1 [REDACTED]', $value);
                $value = preg_replace('/(app_secret|secret)\s*[:=]?\s*[\w\.\-]+/i', '$1 [REDACTED]', $value);
            }
        });

        return $response;
    }
}
