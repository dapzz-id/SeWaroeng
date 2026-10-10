<?php

namespace App\Services\Payment\Reconciliation;

use Carbon\Carbon;

readonly class ProviderEvidence
{
    /**
     * @param  array<string, mixed>  $rawMetadata
     */
    public function __construct(
        public string $provider,
        public string $providerReference,
        public int $amountMinor,
        public ?string $internalReference = null,
        public int $feeMinor = 0,
        public string $currency = 'IDR',
        public ?string $channel = null,
        public string $settlementStatus = 'settled',
        public ?Carbon $occurredAt = null,
        public array $rawMetadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $amount = (int) ($data['provider_amount_minor'] ?? $data['amount_minor'] ?? 0);
        $fee = (int) ($data['fee_amount_minor'] ?? $data['fee_minor'] ?? 0);
        $occurredAt = isset($data['occurred_at'])
            ? Carbon::parse($data['occurred_at'])
            : (isset($data['reconciled_at']) ? Carbon::parse($data['reconciled_at']) : null);

        return new self(
            provider: strtolower(trim((string) ($data['provider'] ?? 'unknown'))),
            providerReference: trim((string) ($data['provider_reference'] ?? $data['reference'] ?? '')),
            amountMinor: $amount,
            internalReference: isset($data['internal_reference']) ? trim((string) $data['internal_reference']) : null,
            feeMinor: $fee,
            currency: strtoupper(trim((string) ($data['currency'] ?? 'IDR'))),
            channel: isset($data['channel']) ? trim((string) $data['channel']) : null,
            settlementStatus: strtolower(trim((string) ($data['settlement_status'] ?? 'settled'))),
            occurredAt: $occurredAt,
            rawMetadata: (array) ($data['raw_evidence'] ?? $data['metadata'] ?? []),
        );
    }

    public function netAmountMinor(): int
    {
        return max(0, $this->amountMinor - $this->feeMinor);
    }
}
