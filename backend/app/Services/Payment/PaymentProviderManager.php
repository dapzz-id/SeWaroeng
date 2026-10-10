<?php

namespace App\Services\Payment;

use App\Exceptions\UnsupportedPaymentProviderException;
use App\Services\Payment\Contracts\PaymentProviderInterface;
use App\Services\Payment\Providers\DuitkuPaymentProvider;
use App\Services\Payment\Providers\ManualCashPaymentProvider;
use App\Services\Payment\Providers\MidtransPaymentProvider;
use App\Services\Payment\Providers\MockPaymentProvider;

class PaymentProviderManager
{
    /**
     * Code-authoritative allowlist of provider adapters.
     * Prevents arbitrary class loading or dynamic reflection from database or untrusted input.
     *
     * @var array<string, class-string<PaymentProviderInterface>>
     */
    public const ALLOWLISTED_ADAPTERS = [
        'manual' => ManualCashPaymentProvider::class,
        'cash' => ManualCashPaymentProvider::class,
        'mock' => MockPaymentProvider::class,
        'midtrans' => MidtransPaymentProvider::class,
        'duitku' => DuitkuPaymentProvider::class,
    ];

    /**
     * @var array<string, PaymentProviderInterface>
     */
    protected array $providers = [];

    public function __construct(
        protected ?PaymentProviderAvailabilityResolver $availabilityResolver = null
    ) {
        $this->availabilityResolver = $availabilityResolver ?? new PaymentProviderAvailabilityResolver;

        $this->registerProvider('manual', new ManualCashPaymentProvider);
        $this->registerProvider('cash', new ManualCashPaymentProvider);
        $this->registerProvider('mock', new MockPaymentProvider);
        $this->registerProvider('midtrans', new MidtransPaymentProvider);
        $this->registerProvider('duitku', new DuitkuPaymentProvider);
    }

    public function registerProvider(string $name, PaymentProviderInterface $provider): void
    {
        $normalized = strtolower(trim($name));
        $this->providers[$normalized] = $provider;
    }

    public function hasProvider(string $name): bool
    {
        return isset($this->providers[strtolower(trim($name))]);
    }

    /**
     * Code-authoritative provider resolution.
     * Resolves strictly from registered in-memory instances matching allowlisted keys.
     * Never instantiates arbitrary classes from database columns.
     */
    public function resolve(?string $providerName): PaymentProviderInterface
    {
        $normalized = strtolower(trim((string) $providerName));

        if ($normalized === '' || $normalized === 'null') {
            $normalized = 'manual';
        }

        if (! isset($this->providers[$normalized])) {
            throw new UnsupportedPaymentProviderException("Payment provider '{$providerName}' is not supported.");
        }

        return $this->providers[$normalized];
    }

    /**
     * Resolve a provider configured with tenant-specific connection credentials,
     * enforcing effective provider availability.
     */
    public function resolveForTenant(?string $providerName, ?string $tenantId = null): PaymentProviderInterface
    {
        $provider = $this->resolve($providerName);

        if (! $tenantId || in_array($provider->getProviderName(), ['manual', 'cash', 'mock'], true)) {
            return $provider;
        }

        // Verify effective availability for tenant
        $check = $this->availabilityResolver->checkAvailability($provider->getProviderName(), $tenantId);
        if (! $check['available']) {
            throw new UnsupportedPaymentProviderException(
                "Payment provider '{$providerName}' is not available for tenant: {$check['reason']}."
            );
        }

        if ($check['connection']) {
            return $provider->withConnection($check['connection']);
        }

        return $provider;
    }

    public function getAvailabilityResolver(): PaymentProviderAvailabilityResolver
    {
        return $this->availabilityResolver;
    }

    /**
     * @return array<string, class-string<PaymentProviderInterface>>
     */
    public static function getAllowlistedAdapters(): array
    {
        return self::ALLOWLISTED_ADAPTERS;
    }

    /**
     * @return array<string>
     */
    public function getRegisteredProviders(): array
    {
        return array_keys($this->providers);
    }
}
