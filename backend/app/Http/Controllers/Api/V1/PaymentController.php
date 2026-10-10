<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Responses\ApiResponse;
use App\Models\Payment;
use App\Models\Sale;
use App\Services\Payment\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function __construct(
        protected PaymentService $paymentService
    ) {}

    /**
     * List payments for the active tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        $query = Payment::query()->with(['attempts', 'refunds', 'creator']);

        if ($request->filled('outlet_id')) {
            $outletId = $request->query('outlet_id');
            if (! $tenantContext->hasOutletAccess($outletId)) {
                return ApiResponse::error(
                    message: 'User is not authorized to access this outlet.',
                    code: 'OUTLET_ACCESS_DENIED',
                    status: Response::HTTP_FORBIDDEN
                );
            }
            $query->whereHas('sale', fn ($q) => $q->where('outlet_id', $outletId));
        } elseif (! $tenantContext->isOwner() && $tenantContext->membership()->outlet_access !== 'all') {
            $assigned = $tenantContext->membership()->assigned_outlet_ids ?? [];
            $query->whereHas('sale', fn ($q) => $q->whereIn('outlet_id', $assigned));
        }

        if ($request->filled('sale_id')) {
            $query->where('sale_id', $request->query('sale_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('method')) {
            $query->where('method', $request->query('method'));
        }

        $payments = $query->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return ApiResponse::success(data: $payments);
    }

    /**
     * Create a new payment record against a finalized sale.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sale_id' => ['required', 'uuid'],
            'amount_minor' => ['required', 'integer', 'min:1'],
            'method' => ['required', 'string', 'in:cash,qris,card,bank_transfer,e_wallet'],
            'provider' => ['nullable', 'string', 'max:50'],
            'client_reference' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ]);

        /** @var Sale $sale */
        $sale = Sale::findOrFail($validated['sale_id']);

        if ($error = $this->assertSaleOutletAccess($sale)) {
            return $error;
        }

        $payment = $this->paymentService->createPayment(
            sale: $sale,
            data: $validated,
            user: $request->user()
        );

        return ApiResponse::success(
            data: $payment->load(['attempts', 'sale']),
            message: 'Payment created successfully.',
            status: 201
        );
    }

    /**
     * Retrieve payment details including attempts and refunds.
     */
    public function show(string $paymentId): JsonResponse
    {
        /** @var Payment $payment */
        $payment = Payment::with(['attempts', 'refunds', 'sale', 'creator'])->findOrFail($paymentId);

        if ($error = $this->assertPaymentOutletAccess($payment)) {
            return $error;
        }

        return ApiResponse::success(data: [
            'payment' => $payment,
            'refundable_amount_minor' => $payment->refundableAmountMinor(),
            'total_refunded_minor' => $payment->totalRefundedMinor(),
        ]);
    }

    /**
     * Process an existing payment through its provider.
     */
    public function process(Request $request, string $paymentId): JsonResponse
    {
        $validated = $request->validate([
            'options' => ['nullable', 'array'],
        ]);

        /** @var Payment $payment */
        $payment = Payment::with('sale')->findOrFail($paymentId);

        if ($error = $this->assertPaymentOutletAccess($payment)) {
            return $error;
        }

        $processed = $this->paymentService->processPayment($payment, $validated['options'] ?? []);

        return ApiResponse::success(
            data: $processed,
            message: "Payment processed with status '{$processed->status}'."
        );
    }

    /**
     * Query and synchronize payment status with external payment provider.
     */
    public function syncStatus(string $paymentId): JsonResponse
    {
        /** @var Payment $payment */
        $payment = Payment::with('sale')->findOrFail($paymentId);

        if ($error = $this->assertPaymentOutletAccess($payment)) {
            return $error;
        }

        $synced = $this->paymentService->syncPaymentStatus($payment);

        return ApiResponse::success(
            data: $synced,
            message: "Payment status synchronized with provider: '{$synced->status}'."
        );
    }

    /**
     * Cancel an unfinalized payment.
     */
    public function cancel(Request $request, string $paymentId): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        /** @var Payment $payment */
        $payment = Payment::with('sale')->findOrFail($paymentId);

        if ($error = $this->assertPaymentOutletAccess($payment)) {
            return $error;
        }

        $cancelled = $this->paymentService->cancelPayment($payment, $validated['reason'] ?? null);

        return ApiResponse::success(
            data: $cancelled,
            message: 'Payment cancelled successfully.'
        );
    }

    /**
     * Issue a refund against a succeeded payment.
     */
    public function refund(Request $request, string $paymentId): JsonResponse
    {
        $validated = $request->validate([
            'amount_minor' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        /** @var Payment $payment */
        $payment = Payment::with('sale')->findOrFail($paymentId);

        if ($error = $this->assertPaymentOutletAccess($payment)) {
            return $error;
        }

        $refund = $this->paymentService->refundPayment(
            payment: $payment,
            amountMinor: $validated['amount_minor'],
            reason: $validated['reason'],
            user: $request->user()
        );

        return ApiResponse::success(
            data: $refund,
            message: 'Refund completed successfully.',
            status: 201
        );
    }

    /**
     * Get all payments and settlement summary for a specific sale.
     */
    public function salePayments(string $saleId): JsonResponse
    {
        /** @var Sale $sale */
        $sale = Sale::with(['payments.attempts', 'payments.refunds'])->findOrFail($saleId);

        if ($error = $this->assertSaleOutletAccess($sale)) {
            return $error;
        }

        $summary = $this->paymentService->getSalePaymentSummary($sale);

        return ApiResponse::success(data: [
            'summary' => $summary,
            'payments' => $sale->payments,
        ]);
    }

    /**
     * Public webhook receiver for payment provider callbacks.
     */
    public function handleWebhook(Request $request, string $provider): JsonResponse
    {
        $result = $this->paymentService->handleWebhook($provider, $request);

        return ApiResponse::success(
            data: $result,
            message: 'Webhook processed.'
        );
    }

    /**
     * Assert that active tenant context user has access to this sale's outlet.
     */
    protected function assertSaleOutletAccess(Sale $sale): ?JsonResponse
    {
        $tenantContext = app(TenantContext::class);
        if (! $tenantContext->hasOutletAccess($sale->outlet_id)) {
            return ApiResponse::error(
                message: 'User is not authorized to access this outlet.',
                code: 'OUTLET_ACCESS_DENIED',
                status: Response::HTTP_FORBIDDEN
            );
        }

        return null;
    }

    /**
     * Assert that active tenant context user has access to this payment's outlet.
     */
    protected function assertPaymentOutletAccess(Payment $payment): ?JsonResponse
    {
        if ($payment->sale) {
            return $this->assertSaleOutletAccess($payment->sale);
        }

        return null;
    }
}
