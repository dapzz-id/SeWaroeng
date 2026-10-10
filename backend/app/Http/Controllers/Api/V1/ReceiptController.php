<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Sale;
use App\Services\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ReceiptController extends Controller
{
    public function __construct(
        protected ReceiptService $receiptService,
    ) {}

    /**
     * Get authoritative receipt for a finalized sale.
     */
    public function show(string $saleId): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $sale = Sale::where('tenant_id', $tenantId)->findOrFail($saleId);

        if (! $tenantContext->hasOutletAccess($sale->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $receipt = $this->receiptService->getReceiptForSale($tenantId, $saleId);

        return ApiResponse::success(
            data: $receipt,
            message: 'Struk penjualan berhasil dimuat.'
        );
    }

    /**
     * Reprint receipt for a finalized sale.
     */
    public function reprint(Request $request, string $saleId): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $tenantId = $tenantContext->id();
        $sale = Sale::where('tenant_id', $tenantId)->findOrFail($saleId);

        if (! $tenantContext->hasOutletAccess($sale->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        $receipt = $this->receiptService->reprintReceipt($tenantId, $saleId, $request->user());

        return ApiResponse::success(
            data: $receipt,
            message: 'Struk berhasil dicetak ulang.'
        );
    }
}
