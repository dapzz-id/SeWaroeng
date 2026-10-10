<?php

namespace App\Services\WhatsApp\DTO;

class WhatsAppSendResult
{
    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public bool $success,
        public ?string $providerMessageId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public bool $isRetryable = false,
        public ?array $rawResponse = null
    ) {}

    public static function success(string $providerMessageId, ?array $rawResponse = null): self
    {
        return new self(
            success: true,
            providerMessageId: $providerMessageId,
            rawResponse: $rawResponse
        );
    }

    public static function failure(string $errorCode, string $errorMessage, bool $isRetryable = false, ?array $rawResponse = null): self
    {
        return new self(
            success: false,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            isRetryable: $isRetryable,
            rawResponse: $rawResponse
        );
    }
}
