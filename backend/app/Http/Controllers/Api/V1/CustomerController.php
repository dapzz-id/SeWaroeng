<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Customer;
use App\Services\CustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends Controller
{
    public function __construct(
        protected CustomerService $customerService,
    ) {}

    /**
     * List customers for the active tenant with search and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $filters = $request->only([
            'search',
            'is_active',
            'sort_by',
            'sort_dir',
        ]);

        $perPage = min((int) $request->query('per_page', 20), 100);
        $paginator = $this->customerService->listCustomers($tenantId, $filters, $perPage);

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->getCollection()->map(fn (Customer $c) => $this->formatCustomer($c))->all()
        );
    }

    /**
     * Create a new customer in the active tenant.
     */
    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'marketing_opt_in' => ['nullable', 'boolean'],
        ]);

        $customer = $this->customerService->createCustomer(
            tenantId: $tenantId,
            data: $validated,
            actor: $request->user(),
        );

        return ApiResponse::success(
            data: $this->formatCustomer($customer),
            message: 'Pelanggan berhasil ditambahkan.',
            status: Response::HTTP_CREATED
        );
    }

    /**
     * Show customer details and CRM summary.
     */
    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $customer = $this->customerService->getCustomer($tenantId, $id);
        $summary = $customer->calculateSummary();

        return ApiResponse::success(
            data: array_merge($this->formatCustomer($customer), [
                'crm_summary' => $summary,
            ])
        );
    }

    /**
     * Update an existing customer.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
            'marketing_opt_in' => ['nullable', 'boolean'],
        ]);

        $customer = $this->customerService->updateCustomer(
            tenantId: $tenantId,
            customerId: $id,
            data: $validated,
            actor: $request->user(),
        );

        return ApiResponse::success(
            data: $this->formatCustomer($customer),
            message: 'Data pelanggan berhasil diperbarui.'
        );
    }

    /**
     * Get customer order history.
     */
    public function history(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $perPage = min((int) $request->query('per_page', 20), 100);

        $paginator = $this->customerService->getCustomerHistory($tenantId, $id, $perPage);

        return ApiResponse::paginated(
            paginator: $paginator,
            data: $paginator->items()
        );
    }

    /**
     * Get customer CRM summary and segmentation.
     */
    public function summary(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $summary = $this->customerService->getCustomerSummary($tenantId, $id);

        return ApiResponse::success(data: $summary);
    }

    /**
     * Format customer representation.
     */
    protected function formatCustomer(Customer $customer): array
    {
        return [
            'id' => $customer->id,
            'tenant_id' => $customer->tenant_id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'notes' => $customer->notes,
            'is_active' => $customer->is_active,
            'marketing_opt_in' => $customer->marketing_opt_in,
            'created_at' => $customer->created_at?->toIso8601String(),
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }
}
