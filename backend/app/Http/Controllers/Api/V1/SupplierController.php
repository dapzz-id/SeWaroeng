<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Supplier;
use App\Services\PurchasingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function __construct(
        protected PurchasingService $purchasingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $query = Supplier::where('tenant_id', $tenantId);

        if ($request->has('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('code', 'ilike', "%{$search}%")
                    ->orWhere('contact_name', 'ilike', "%{$search}%");
            });
        }

        $perPage = min((int) $request->query('per_page', 20), 100);
        $suppliers = $query->orderBy('name')->paginate($perPage);

        return ApiResponse::paginated(
            paginator: $suppliers,
            data: $suppliers->map(fn ($s) => [
                'id' => $s->id,
                'tenant_id' => $s->tenant_id,
                'code' => $s->code,
                'name' => $s->name,
                'contact_name' => $s->contact_name,
                'phone' => $s->phone,
                'email' => $s->email,
                'address' => $s->address,
                'tax_identifier' => $s->tax_identifier,
                'payment_terms_days' => $s->payment_terms_days,
                'notes' => $s->notes,
                'status' => $s->status,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->all()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('suppliers', 'code')->where('tenant_id', $tenantId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'tax_identifier' => ['nullable', 'string', 'max:50'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', 'string', 'in:active,inactive'],
        ]);

        $supplier = $this->purchasingService->createSupplier(
            tenantId: $tenantId,
            data: $validated,
            userId: $request->user()->id
        );

        return ApiResponse::success(
            data: [
                'id' => $supplier->id,
                'tenant_id' => $supplier->tenant_id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'contact_name' => $supplier->contact_name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'address' => $supplier->address,
                'tax_identifier' => $supplier->tax_identifier,
                'payment_terms_days' => $supplier->payment_terms_days,
                'notes' => $supplier->notes,
                'status' => $supplier->status,
                'created_at' => $supplier->created_at?->toIso8601String(),
            ],
            message: 'Supplier created successfully.',
            status: 201
        );
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $supplier = Supplier::where('tenant_id', $tenantId)->findOrFail($id);

        return ApiResponse::success(data: [
            'id' => $supplier->id,
            'tenant_id' => $supplier->tenant_id,
            'code' => $supplier->code,
            'name' => $supplier->name,
            'contact_name' => $supplier->contact_name,
            'phone' => $supplier->phone,
            'email' => $supplier->email,
            'address' => $supplier->address,
            'tax_identifier' => $supplier->tax_identifier,
            'payment_terms_days' => $supplier->payment_terms_days,
            'notes' => $supplier->notes,
            'status' => $supplier->status,
            'created_at' => $supplier->created_at?->toIso8601String(),
        ]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();

        $validated = $request->validate([
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('suppliers', 'code')->where('tenant_id', $tenantId)->ignore($id),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'tax_identifier' => ['nullable', 'string', 'max:50'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string'],
            'status' => ['sometimes', 'string', 'in:active,inactive'],
        ]);

        $supplier = $this->purchasingService->updateSupplier(
            tenantId: $tenantId,
            supplierId: $id,
            data: $validated,
            userId: $request->user()->id
        );

        return ApiResponse::success(
            data: [
                'id' => $supplier->id,
                'tenant_id' => $supplier->tenant_id,
                'code' => $supplier->code,
                'name' => $supplier->name,
                'contact_name' => $supplier->contact_name,
                'phone' => $supplier->phone,
                'email' => $supplier->email,
                'address' => $supplier->address,
                'tax_identifier' => $supplier->tax_identifier,
                'payment_terms_days' => $supplier->payment_terms_days,
                'notes' => $supplier->notes,
                'status' => $supplier->status,
                'created_at' => $supplier->created_at?->toIso8601String(),
            ],
            message: 'Supplier updated successfully.'
        );
    }
}
