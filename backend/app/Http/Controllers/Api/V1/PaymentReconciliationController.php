<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Payment;
use App\Models\PaymentReconciliation;
use App\Services\Payment\Reconciliation\PaymentReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentReconciliationController extends Controller
{
    public function __construct(
        protected PaymentReconciliationService $reconciliationService
    ) {}

    /**
     * List reconciliation records for the tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $outletId = $request->query('outlet_id');
        if ($outletId && ! $tenantContext->hasOutletAccess($outletId)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $assignedOutletIds = null;
        if (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $assignedOutletIds = $tenantContext->membership()->assigned_outlet_ids ?? [];
        }

        $filters = [
            'outlet_id' => $outletId,
            'status' => $request->query('status'),
            'provider' => $request->query('provider'),
            'start_date' => $request->query('start_date'),
            'end_date' => $request->query('end_date'),
        ];

        $reconciliations = $this->reconciliationService->listReconciliations(
            tenantId: $tenantId,
            filters: $filters,
            perPage: $request->integer('per_page', 20),
            assignedOutletIds: $assignedOutletIds
        );

        return ApiResponse::success(data: $reconciliations);
    }

    /**
     * Aggregate summary metrics for payment reconciliation.
     */
    public function summary(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $outletId = $request->query('outlet_id');
        if ($outletId && ! $tenantContext->hasOutletAccess($outletId)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $assignedOutletIds = null;
        if (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $assignedOutletIds = $tenantContext->membership()->assigned_outlet_ids ?? [];
        }

        $summary = $this->reconciliationService->getSummary(
            tenantId: $tenantId,
            outletId: $outletId,
            assignedOutletIds: $assignedOutletIds
        );

        return ApiResponse::success(data: $summary);
    }

    /**
     * Retrieve single reconciliation detail.
     */
    public function show(string $id): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        /** @var PaymentReconciliation $recon */
        $recon = PaymentReconciliation::with(['payment.sale', 'outlet', 'reconciler', 'resolver'])
            ->where('tenant_id', $tenantId)
            ->findOrFail($id);

        if ($recon->outlet_id && ! $tenantContext->hasOutletAccess($recon->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        return ApiResponse::success(data: $recon);
    }

    /**
     * Reconcile specific internal payment.
     */
    public function reconcilePayment(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'payment_id' => ['required', 'uuid'],
        ]);

        /** @var Payment $payment */
        $payment = Payment::with('sale')
            ->where('tenant_id', $tenantId)
            ->findOrFail($validated['payment_id']);

        if ($payment->sale && ! $tenantContext->hasOutletAccess($payment->sale->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $result = $this->reconciliationService->reconcilePayment(
            payment: $payment,
            userId: $request->user()?->id
        );

        return ApiResponse::success(
            data: $result,
            message: "Payment reconciliation status: '{$result->reconciliation_status}'."
        );
    }

    /**
     * Ingest batch of provider statement rows.
     */
    public function importBatch(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'outlet_id' => ['nullable', 'uuid'],
            'items' => ['required', 'array', 'min:1'],
            'items.*' => ['required', 'array'],
        ]);

        $outletId = $validated['outlet_id'] ?? null;
        if ($outletId && ! $tenantContext->hasOutletAccess($outletId)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $result = $this->reconciliationService->importBatch(
            tenantId: $tenantId,
            items: $validated['items'],
            userId: $request->user()?->id,
            outletId: $outletId
        );

        return ApiResponse::success(
            data: $result,
            message: "Batch import processed {$result['total_processed']} items."
        );
    }

    /**
     * Resolve a discrepancy or unmatched record.
     */
    public function resolveDiscrepancy(Request $request, string $id): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();

        $validated = $request->validate([
            'resolution_notes' => ['required', 'string', 'min:3', 'max:1000'],
        ]);

        /** @var PaymentReconciliation $recon */
        $recon = PaymentReconciliation::where('tenant_id', $tenantId)->findOrFail($id);

        if ($recon->outlet_id && ! $tenantContext->hasOutletAccess($recon->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $resolved = $this->reconciliationService->resolveDiscrepancy(
            reconciliation: $recon,
            notes: $validated['resolution_notes'],
            userId: $request->user()?->id ?? '00000000-0000-0000-0000-000000000000'
        );

        return ApiResponse::success(
            data: $resolved,
            message: 'Reconciliation discrepancy successfully resolved.'
        );
    }
}
