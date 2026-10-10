<?php

namespace App\Services;

use App\Domain\Tenant\TenantContext;
use App\Domain\Tenant\TenantManager;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    protected function ensureTenantRls(string $tenantId): void
    {
        try {
            DB::statement("SELECT set_config('app.current_tenant_id', ?, true)", [$tenantId]);
        } catch (\Throwable) {
            // Ignored if non-pgsql connection or testing mock
        }
    }

    protected function resolveTenantId(?string $explicitTenantId = null): string
    {
        if ($explicitTenantId) {
            return $explicitTenantId;
        }

        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasContext()) {
            return $tenantManager->currentTenantId();
        }

        if (app()->bound(TenantContext::class)) {
            $context = app(TenantContext::class);
            if ($context->hasTenant()) {
                return $context->id();
            }
        }

        throw new \RuntimeException('Tenant context could not be resolved for Customer operation.');
    }

    protected function executeForTenant(string $tenantId, callable $callback): mixed
    {
        $tenantManager = app(TenantManager::class);
        if ($tenantManager->hasContext() && $tenantManager->currentTenantId() === $tenantId) {
            $this->ensureTenantRls($tenantId);

            return $callback();
        }

        return $tenantManager->runForTenant($tenantId, $callback);
    }

    /**
     * List customers for the tenant with search, filtering, and pagination.
     */
    public function listCustomers(string $tenantId, array $filters = [], int $perPage = 20): LengthAwarePaginator
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $filters, $perPage) {
            $query = Customer::where('tenant_id', $tenantId);

            if (! empty($filters['search'])) {
                $search = trim((string) $filters['search']);
                $normalizedSearch = PhoneNormalizer::normalize($search);
                $query->where(function ($q) use ($search, $normalizedSearch) {
                    $q->where('name', 'ilike', "%{$search}%")
                        ->orWhere('phone', 'ilike', "%{$search}%")
                        ->orWhere('email', 'ilike', "%{$search}%");

                    if ($normalizedSearch) {
                        $q->orWhere('phone', 'ilike', "%{$normalizedSearch}%");
                    }

                    if (str_starts_with($search, '08')) {
                        $q->orWhere('phone', 'ilike', '%+62'.substr($search, 1).'%');
                    }
                });
            }

            if (isset($filters['is_active'])) {
                $isActive = filter_var($filters['is_active'], FILTER_VALIDATE_BOOLEAN);
                $query->where('is_active', $isActive);
            }

            $sortBy = $filters['sort_by'] ?? 'created_at';
            $sortDir = strtolower($filters['sort_dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

            if (in_array($sortBy, ['name', 'created_at', 'updated_at'], true)) {
                $query->orderBy($sortBy, $sortDir);
            } else {
                $query->orderByDesc('created_at');
            }

            return $query->paginate($perPage);
        });
    }

    /**
     * Get single customer by id.
     */
    public function getCustomer(string $tenantId, string $customerId): Customer
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $customerId) {
            return Customer::where('tenant_id', $tenantId)->findOrFail($customerId);
        });
    }

    /**
     * Create a new customer with deterministic normalization and duplicate prevention.
     */
    public function createCustomer(string $tenantId, array $data, ?User $actor = null): Customer
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $data, $actor) {
            return DB::transaction(function () use ($tenantId, $data, $actor) {
                $this->ensureTenantRls($tenantId);

                $normalizedPhone = null;
                if (! empty($data['phone'])) {
                    $normalizedPhone = PhoneNormalizer::normalize($data['phone']);
                    if (! PhoneNormalizer::isValid($data['phone'])) {
                        throw ValidationException::withMessages([
                            'phone' => ['Format nomor telepon tidak valid. Gunakan format nomor telepon seluler Indonesia (+628...).'],
                        ]);
                    }

                    // Tenant-scoped duplicate check
                    $existingPhone = Customer::where('tenant_id', $tenantId)
                        ->where('phone', $normalizedPhone)
                        ->exists();

                    if ($existingPhone) {
                        throw ValidationException::withMessages([
                            'phone' => ['Nomor telepon sudah terdaftar untuk pelanggan lain dalam bisnis ini.'],
                        ]);
                    }
                }

                $normalizedEmail = null;
                if (! empty($data['email'])) {
                    $normalizedEmail = strtolower(trim((string) $data['email']));

                    // Tenant-scoped duplicate check
                    $existingEmail = Customer::where('tenant_id', $tenantId)
                        ->where('email', $normalizedEmail)
                        ->exists();

                    if ($existingEmail) {
                        throw ValidationException::withMessages([
                            'email' => ['Alamat email sudah terdaftar untuk pelanggan lain dalam bisnis ini.'],
                        ]);
                    }
                }

                $customer = Customer::create([
                    'tenant_id' => $tenantId,
                    'name' => trim((string) $data['name']),
                    'phone' => $normalizedPhone,
                    'email' => $normalizedEmail,
                    'notes' => $data['notes'] ?? null,
                    'is_active' => $data['is_active'] ?? true,
                    'marketing_opt_in' => $data['marketing_opt_in'] ?? false,
                ]);

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $actor?->id ?? auth()->id(),
                    'action' => 'customer_created',
                    'entity_type' => Customer::class,
                    'entity_id' => $customer->id,
                    'after' => [
                        'name' => $customer->name,
                        'phone' => $customer->phone,
                        'email' => $customer->email,
                        'is_active' => $customer->is_active,
                        'marketing_opt_in' => $customer->marketing_opt_in,
                    ],
                    'created_at' => now(),
                ]);

                return $customer;
            });
        });
    }

    /**
     * Update an existing customer.
     */
    public function updateCustomer(string $tenantId, string $customerId, array $data, ?User $actor = null): Customer
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $customerId, $data, $actor) {
            return DB::transaction(function () use ($tenantId, $customerId, $data, $actor) {
                $this->ensureTenantRls($tenantId);

                /** @var Customer $customer */
                $customer = Customer::where('tenant_id', $tenantId)->lockForUpdate()->findOrFail($customerId);
                $before = $customer->toArray();

                $updateData = [];

                if (array_key_exists('name', $data)) {
                    $updateData['name'] = trim((string) $data['name']);
                }

                if (array_key_exists('phone', $data)) {
                    if (! empty($data['phone'])) {
                        $normalizedPhone = PhoneNormalizer::normalize($data['phone']);
                        if (! PhoneNormalizer::isValid($data['phone'])) {
                            throw ValidationException::withMessages([
                                'phone' => ['Format nomor telepon tidak valid. Gunakan format nomor telepon seluler Indonesia (+628...).'],
                            ]);
                        }

                        $existingPhone = Customer::where('tenant_id', $tenantId)
                            ->where('phone', $normalizedPhone)
                            ->where('id', '!=', $customer->id)
                            ->exists();

                        if ($existingPhone) {
                            throw ValidationException::withMessages([
                                'phone' => ['Nomor telepon sudah terdaftar untuk pelanggan lain dalam bisnis ini.'],
                            ]);
                        }

                        $updateData['phone'] = $normalizedPhone;
                    } else {
                        $updateData['phone'] = null;
                    }
                }

                if (array_key_exists('email', $data)) {
                    if (! empty($data['email'])) {
                        $normalizedEmail = strtolower(trim((string) $data['email']));

                        $existingEmail = Customer::where('tenant_id', $tenantId)
                            ->where('email', $normalizedEmail)
                            ->where('id', '!=', $customer->id)
                            ->exists();

                        if ($existingEmail) {
                            throw ValidationException::withMessages([
                                'email' => ['Alamat email sudah terdaftar untuk pelanggan lain dalam bisnis ini.'],
                            ]);
                        }

                        $updateData['email'] = $normalizedEmail;
                    } else {
                        $updateData['email'] = null;
                    }
                }

                if (array_key_exists('notes', $data)) {
                    $updateData['notes'] = $data['notes'];
                }

                if (array_key_exists('is_active', $data)) {
                    $updateData['is_active'] = (bool) $data['is_active'];
                }

                if (array_key_exists('marketing_opt_in', $data)) {
                    $updateData['marketing_opt_in'] = (bool) $data['marketing_opt_in'];
                }

                $customer->update($updateData);

                $action = (isset($updateData['is_active']) && ! $updateData['is_active'] && $before['is_active'])
                    ? 'customer_deactivated'
                    : 'customer_updated';

                AuditLog::create([
                    'tenant_id' => $tenantId,
                    'actor_user_id' => $actor?->id ?? auth()->id(),
                    'action' => $action,
                    'entity_type' => Customer::class,
                    'entity_id' => $customer->id,
                    'before' => $before,
                    'after' => $customer->fresh()->toArray(),
                    'created_at' => now(),
                ]);

                return $customer->fresh();
            });
        });
    }

    /**
     * Get read-only paginated transaction history for a customer.
     */
    public function getCustomerHistory(string $tenantId, string $customerId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $customerId, $perPage) {
            $customer = Customer::where('tenant_id', $tenantId)->findOrFail($customerId);

            $paginator = Sale::where('tenant_id', $tenantId)
                ->where('customer_id', $customer->id)
                ->with(['outlet', 'items', 'payments'])
                ->orderByDesc('opened_at')
                ->paginate($perPage);

            $paginator->getCollection()->transform(function (Sale $sale) {
                return $this->formatHistorySale($sale);
            });

            return $paginator;
        });
    }

    /**
     * Get CRM summary and segmentation for a customer.
     */
    public function getCustomerSummary(string $tenantId, string $customerId): array
    {
        return $this->executeForTenant($tenantId, function () use ($tenantId, $customerId) {
            $customer = Customer::where('tenant_id', $tenantId)->findOrFail($customerId);

            return $customer->calculateSummary();
        });
    }

    /**
     * Format a Sale into a customer order history record.
     */
    protected function formatHistorySale(Sale $sale): array
    {
        // Compute payment status from authoritative payments
        $paidMinor = $sale->getPaidAmountMinor();
        $refundedMinor = $sale->getRefundedAmountMinor();
        $outstandingMinor = $sale->getOutstandingAmountMinor();

        $paymentStatus = 'unpaid';
        if ($sale->payments->isEmpty()) {
            $paymentStatus = 'unpaid';
        } elseif ($refundedMinor > 0 && $refundedMinor >= $paidMinor) {
            $paymentStatus = 'refunded';
        } elseif ($refundedMinor > 0) {
            $paymentStatus = 'partially_refunded';
        } elseif ($paidMinor > 0 && $outstandingMinor === 0) {
            $paymentStatus = 'paid';
        } elseif ($paidMinor > 0) {
            $paymentStatus = 'partially_paid';
        } elseif ($sale->payments->contains(fn ($p) => in_array($p->status, ['processing', 'pending'], true))) {
            $paymentStatus = 'pending';
        } elseif ($sale->payments->contains(fn ($p) => $p->status === 'failed')) {
            $paymentStatus = 'failed';
        }

        return [
            'sale_id' => $sale->id,
            'reference_number' => $sale->reference_number,
            'outlet_id' => $sale->outlet_id,
            'outlet_name' => $sale->outlet?->name,
            'status' => $sale->status,
            'opened_at' => $sale->opened_at?->toIso8601String(),
            'finalized_at' => $sale->finalized_at?->toIso8601String(),
            'item_count' => (int) $sale->items->sum('quantity'),
            'subtotal_minor' => (int) $sale->subtotal_minor,
            'discount_minor' => (int) $sale->discount_minor,
            'tax_minor' => (int) $sale->tax_minor,
            'total_minor' => (int) $sale->total_minor,
            'paid_minor' => $paidMinor,
            'refunded_minor' => $refundedMinor,
            'payment_status' => $paymentStatus,
        ];
    }
}
