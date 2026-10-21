<?php

namespace App\Services\WhatsApp\Providers;

use App\Models\TenantWhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Services\WhatsApp\DTO\WhatsAppMessagePayload;
use App\Services\WhatsApp\DTO\WhatsAppSendResult;
use App\Services\WhatsApp\DTO\WhatsAppWebhookEvent;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MetaCloudWhatsAppProvider implements WhatsAppProviderInterface
{
    /**
     * Default candidate Graph API version evaluated and contract-tested.
     */
    public const DEFAULT_API_VERSION = 'v25.0';

    /**
     * Active supported versions tested against SeWaroeng contract suite.
     */
    public const SUPPORTED_API_VERSIONS = ['v21.0', 'v22.0', 'v25.0'];

    /**
     * Upstream released by Meta, but not yet verified or allowlisted in SeWaroeng adapters.
     * Note: v26.0 has been released upstream by Meta, but requires contract test staging verification before enablement.
     */
    public const UPSTREAM_RELEASED_VERSIONS = ['v26.0'];

    /**
     * Deprecated and expired Graph API versions (rejected without network call).
     */
    public const EXPIRED_API_VERSIONS = ['v20.0', 'v19.0', 'v18.0', 'v17.0', 'v16.0'];

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

        // Fail-Closed Template & Customer Service Window Contract Validation
        $configuredLanguage = null;
        if (! empty($payload->templateName)) {
            $templatesConfig = $connection->configuration['templates'] ?? [];
            if (! array_key_exists($payload->templateName, $templatesConfig)) {
                return WhatsAppSendResult::failure(
                    errorCode: 'TEMPLATE_MISSING',
                    errorMessage: "Template '{$payload->templateName}' is not configured on this connection.",
                    isRetryable: false
                );
            }

            $templateDef = $templatesConfig[$payload->templateName];
            $status = is_array($templateDef) ? ($templateDef['status'] ?? null) : $templateDef;
            $statusUpper = strtoupper(trim((string) $status));
            $isEnabled = is_array($templateDef) ? ($templateDef['enabled'] ?? true) : true;

            if ($statusUpper === 'REJECTED') {
                return WhatsAppSendResult::failure(
                    errorCode: 'TEMPLATE_REJECTED',
                    errorMessage: "Template '{$payload->templateName}' has been rejected by Meta and cannot be sent.",
                    isRetryable: false
                );
            }

            if (! $isEnabled || $statusUpper === 'DISABLED') {
                return WhatsAppSendResult::failure(
                    errorCode: 'TEMPLATE_DISABLED',
                    errorMessage: "Template '{$payload->templateName}' is disabled and cannot be sent.",
                    isRetryable: false
                );
            }

            if ($statusUpper === 'PAUSED') {
                return WhatsAppSendResult::failure(
                    errorCode: 'TEMPLATE_PAUSED',
                    errorMessage: "Template '{$payload->templateName}' is paused and cannot be sent.",
                    isRetryable: false
                );
            }

            if ($statusUpper === 'PENDING') {
                return WhatsAppSendResult::failure(
                    errorCode: 'TEMPLATE_PENDING',
                    errorMessage: "Template '{$payload->templateName}' is pending approval and cannot be sent.",
                    isRetryable: false
                );
            }

            if ($statusUpper !== 'APPROVED') {
                return WhatsAppSendResult::failure(
                    errorCode: 'TEMPLATE_NOT_APPROVED',
                    errorMessage: "Template '{$payload->templateName}' is in non-approved status '{$statusUpper}' and cannot be sent.",
                    isRetryable: false
                );
            }

            // Language validation if configured on template definition
            $configuredLanguage = is_array($templateDef) ? ($templateDef['language'] ?? null) : null;
            if ($configuredLanguage !== null && ($payload->metadata['language_code'] ?? null) !== null) {
                if ($payload->metadata['language_code'] !== $configuredLanguage) {
                    return WhatsAppSendResult::failure(
                        errorCode: 'TEMPLATE_LANGUAGE_MISMATCH',
                        errorMessage: "Template '{$payload->templateName}' configured language is '{$configuredLanguage}', requested '{$payload->metadata['language_code']}'.",
                        isRetryable: false
                    );
                }
            }

            // Parameter and component validation
            if (is_array($templateDef)) {
                $paramCount = count($payload->templateParameters ?? []);
                if (isset($templateDef['required_param_count']) && $paramCount !== (int) $templateDef['required_param_count']) {
                    return WhatsAppSendResult::failure(
                        errorCode: 'TEMPLATE_PARAM_MISMATCH',
                        errorMessage: "Template '{$payload->templateName}' requires {$templateDef['required_param_count']} parameters, got {$paramCount}.",
                        isRetryable: false
                    );
                }
                if (isset($templateDef['components']) && is_array($templateDef['components'])) {
                    foreach ($templateDef['components'] as $component) {
                        if (($component['type'] ?? '') === 'body' && isset($component['param_count'])) {
                            if ($paramCount !== (int) $component['param_count']) {
                                return WhatsAppSendResult::failure(
                                    errorCode: 'TEMPLATE_PARAM_MISMATCH',
                                    errorMessage: "Template '{$payload->templateName}' body requires {$component['param_count']} parameters, got {$paramCount}.",
                                    isRetryable: false
                                );
                            }
                        }
                    }
                }
            }
        } else {
            // Freeform text (strictly prohibited outside the active 24-hour customer service window)
            $isSessionMessage = ($payload->messageType === WhatsAppMessage::TYPE_MERCHANT_QUERY_REPLY);
            $inboundTimestamp = $payload->metadata['inbound_timestamp'] ?? null;
            $isWithinWindow = $isSessionMessage;

            if ($inboundTimestamp) {
                $inboundTime = is_numeric($inboundTimestamp)
                    ? Carbon::createFromTimestamp((int) $inboundTimestamp)
                    : Carbon::parse($inboundTimestamp);
                if ($inboundTime->diffInSeconds(now()) >= 86400) {
                    $isWithinWindow = false;
                }
            }

            if (! $isWithinWindow) {
                return WhatsAppSendResult::failure(
                    errorCode: 'FREEFORM_NOT_PERMITTED',
                    errorMessage: 'Free-form text is strictly prohibited outside the active 24-hour customer service window. An approved template is required.',
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
            $languageCode = $payload->metadata['language_code'] ?? ($configuredLanguage ?? 'id');

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
            // Freeform text (within active 24hr customer service window)
            $body['type'] = 'text';
            $body['text'] = [
                'preview_url' => false,
                'body' => $payload->textBody ?? '',
            ];
        }

        $trackingId = $payload->metadata['message_id'] ?? ($payload->metadata['tracking_id'] ?? null);
        if ($trackingId) {
            $body['biz_opaque_callback_data'] = (string) $trackingId;
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
    public function verifyConnection(array $credentials, ?string $phoneNumberId = null, string $environment = 'sandbox', ?string $apiVersion = null): array
    {
        $accessToken = $credentials['access_token'] ?? null;

        if (empty($accessToken) || empty($phoneNumberId)) {
            return [
                'verified' => false,
                'error_code' => 'MISSING_CREDENTIALS',
                'error_message' => 'Access token and Phone Number ID are required for Meta Cloud API.',
            ];
        }

        $version = $apiVersion ?? config('services.whatsapp.meta.graph_version', self::DEFAULT_API_VERSION);
        if (! in_array($version, self::SUPPORTED_API_VERSIONS, true)) {
            return [
                'verified' => false,
                'error_code' => 'UNSUPPORTED_API_VERSION',
                'error_message' => "Unsupported or expired Meta Graph API version '{$version}'. Supported versions: ".implode(', ', self::SUPPORTED_API_VERSIONS),
            ];
        }

        $url = "https://graph.facebook.com/{$version}/{$phoneNumberId}";

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
                        rawPayload: $statusItem,
                        isInbound: false,
                        senderPhone: null,
                        messageText: null,
                        callbackData: $statusItem['biz_opaque_callback_data'] ?? null
                    );
                }

                // Inbound customer/merchant messages
                $messages = $value['messages'] ?? [];
                foreach ($messages as $msgItem) {
                    $msgId = $msgItem['id'] ?? null;
                    $from = $msgItem['from'] ?? null;
                    $textBody = $msgItem['text']['body'] ?? null;

                    if (! $msgId || ! $from) {
                        continue;
                    }

                    $events[] = new WhatsAppWebhookEvent(
                        providerMessageId: (string) $msgId,
                        status: 'inbound_message',
                        timestamp: isset($msgItem['timestamp']) ? (string) $msgItem['timestamp'] : null,
                        recipientPhone: null,
                        errorCode: null,
                        errorMessage: null,
                        phoneNumberId: $phoneNumberId ? (string) $phoneNumberId : null,
                        rawPayload: $msgItem,
                        isInbound: true,
                        senderPhone: (string) $from,
                        messageText: $textBody
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
