<?php

namespace App\Services\WhatsApp;

use App\Exceptions\UnsupportedWhatsAppProviderException;
use App\Models\Tenant;
use App\Models\TenantWhatsAppConnection;
use App\Services\WhatsApp\Contracts\WhatsAppProviderInterface;
use App\Services\WhatsApp\Providers\MetaCloudWhatsAppProvider;
use App\Services\WhatsApp\Providers\MockWhatsAppProvider;

class WhatsAppProviderManager
{
    /**
     * Code-authoritative allowlist of provider adapters.
     * Prevents arbitrary class loading or dynamic reflection from untrusted input.
     *
     * @var array<string, class-string<WhatsAppProviderInterface>>
     */
    public const ALLOWLISTED_ADAPTERS = [
        'mock' => MockWhatsAppProvider::class,
        'meta_cloud' => MetaCloudWhatsAppProvider::class,
    ];

    /**
     * @var array<string, WhatsAppProviderInterface>
     */
    protected array $providers = [];

    public function __construct()
    {
        $this->registerProvider('mock', new MockWhatsAppProvider);
        $meta = new MetaCloudWhatsAppProvider;
        $this->registerProvider('meta_cloud', $meta);
        $this->registerProvider('meta', $meta);
    }

    public function registerProvider(string $name, WhatsAppProviderInterface $provider): void
    {
        $normalized = strtolower(trim($name));
        $this->providers[$normalized] = $provider;
    }

    public function hasProvider(string $name): bool
    {
        return isset($this->providers[strtolower(trim($name))]);
    }

    /**
     * Resolve provider adapter by name from registered in-memory instances.
     */
    public function resolve(?string $providerName): WhatsAppProviderInterface
    {
        $normalized = strtolower(trim((string) $providerName));

        if ($normalized === '' || $normalized === 'null') {
            $normalized = 'mock';
        }

        if (! isset($this->providers[$normalized])) {
            throw new UnsupportedWhatsAppProviderException("WhatsApp provider '{$providerName}' is not supported.");
        }

        return $this->providers[$normalized];
    }

    /**
     * Resolve provider and active connection for a specific tenant.
     *
     * @return array{
     *     provider: WhatsAppProviderInterface,
     *     connection: ?TenantWhatsAppConnection
     * }
     */
    public function resolveForTenant(Tenant|string $tenant, ?string $providerName = null): array
    {
        $tenantId = $tenant instanceof Tenant ? (string) $tenant->id : $tenant;

        $query = TenantWhatsAppConnection::query()
            ->where('tenant_id', $tenantId)
            ->where('status', TenantWhatsAppConnection::STATUS_ACTIVE);

        if ($providerName) {
            $query->where('provider', strtolower(trim($providerName)));
        } else {
            // Prefer default connection if available, otherwise latest active
            $query->orderByDesc('is_default')->latest('updated_at');
        }

        /** @var TenantWhatsAppConnection|null $connection */
        $connection = $query->first();

        $resolvedProviderName = $connection?->provider ?? ($providerName ?? 'mock');
        $provider = $this->resolve($resolvedProviderName);

        return [
            'provider' => $provider,
            'connection' => $connection,
        ];
    }

    /**
     * @return array<string>
     */
    public function getRegisteredProviders(): array
    {
        return array_keys($this->providers);
    }
}
