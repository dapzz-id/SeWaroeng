<?php

namespace App\Services\Payment\Providers;

use App\Models\Payment;
use App\Models\TenantPaymentConnection;
use App\Services\Payment\Contracts\PaymentProviderInterface;
use Illuminate\Http\Request;

abstract class AbstractPaymentProvider implements PaymentProviderInterface
{
    protected ?TenantPaymentConnection $connection = null;

    public function withConnection(?TenantPaymentConnection $connection): self
    {
        $clone = clone $this;
        $clone->connection = $connection;

        return $clone;
    }

    public function supportsRefund(?Payment $payment = null): bool
    {
        return false;
    }

    public function verifyConnection(array $credentials, string $environment = 'sandbox'): array
    {
        return [
            'verified' => true,
            'error_code' => null,
            'error_message' => null,
        ];
    }

    public function extractMerchantIdentifier(Request $request): ?string
    {
        return null;
    }
}
