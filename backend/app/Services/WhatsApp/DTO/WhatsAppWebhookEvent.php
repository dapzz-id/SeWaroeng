<?php

namespace App\Services\WhatsApp\DTO;

class WhatsAppWebhookEvent
{
    /**
     * @param  array<string, mixed>  $rawPayload
     */
    public function __construct(
        public string $providerMessageId,
        public string $status, // 'sent', 'delivered', 'read', 'failed'
        public ?string $timestamp = null,
        public ?string $recipientPhone = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?string $phoneNumberId = null,
        public array $rawPayload = [],
        public bool $isInbound = false,
        public ?string $senderPhone = null,
        public ?string $messageText = null,
        public ?string $callbackData = null
    ) {}
}
