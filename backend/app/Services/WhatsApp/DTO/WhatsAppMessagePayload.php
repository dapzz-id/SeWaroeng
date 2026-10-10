<?php

namespace App\Services\WhatsApp\DTO;

class WhatsAppMessagePayload
{
    /**
     * @param  array<string, mixed>  $templateParameters
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $recipientPhone,
        public string $messageType,
        public ?string $templateName = null,
        public array $templateParameters = [],
        public ?string $textBody = null,
        public array $metadata = []
    ) {}
}
