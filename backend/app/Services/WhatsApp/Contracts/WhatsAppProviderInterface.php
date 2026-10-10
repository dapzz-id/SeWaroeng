<?php

namespace App\Services\WhatsApp\Contracts;

use App\Models\TenantWhatsAppConnection;
use App\Services\WhatsApp\DTO\WhatsAppMessagePayload;
use App\Services\WhatsApp\DTO\WhatsAppSendResult;
use App\Services\WhatsApp\DTO\WhatsAppWebhookEvent;
use Illuminate\Http\Request;

interface WhatsAppProviderInterface
{
    /**
     * Unique code of provider adapter.
     */
    public function getProviderName(): string;

    /**
     * Send a normalized message payload via the provider.
     */
    public function sendMessage(TenantWhatsAppConnection $connection, WhatsAppMessagePayload $payload): WhatsAppSendResult;

    /**
     * Perform safe connection verification with provider.
     *
     * @param  array<string, mixed>  $credentials
     * @return array{
     *     verified: bool,
     *     error_code: ?string,
     *     error_message: ?string,
     *     capabilities?: ?array<string, mixed>
     * }
     */
    public function verifyConnection(array $credentials, ?string $phoneNumberId = null, string $environment = 'sandbox'): array;

    /**
     * Verify incoming webhook authenticity (signature/token).
     */
    public function verifyWebhookSignature(Request $request, TenantWhatsAppConnection $connection): bool;

    /**
     * Parse webhook delivery status events from incoming request.
     *
     * @return array<WhatsAppWebhookEvent>
     */
    public function parseWebhookEvents(Request $request): array;
}
