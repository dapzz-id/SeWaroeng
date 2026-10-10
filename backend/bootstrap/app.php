<?php

use App\Exceptions\ActiveShiftAlreadyExistsException;
use App\Exceptions\BookingOverlapException;
use App\Exceptions\IdempotencyReplayUnavailableException;
use App\Exceptions\InsufficientDrawerCashException;
use App\Exceptions\InsufficientLoyaltyPointsException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InventoryLocationOutletMismatchException;
use App\Exceptions\LoyaltyAccountInactiveException;
use App\Exceptions\NoPosInventoryLocationException;
use App\Exceptions\OpnameAlreadyApprovedException;
use App\Exceptions\OrderInvalidStateException;
use App\Exceptions\PaymentAmountExceedsOutstandingException;
use App\Exceptions\PaymentInvalidStateException;
use App\Exceptions\PaymentReconciliationInvalidStateException;
use App\Exceptions\PaymentReconciliationNotFoundException;
use App\Exceptions\PaymentRefundExceedsPaidException;
use App\Exceptions\PaymentSaleNotFinalizedException;
use App\Exceptions\PaymentWebhookAmountMismatchException;
use App\Exceptions\PaymentWebhookCurrencyMismatchException;
use App\Exceptions\PriceNotConfiguredException;
use App\Exceptions\ProductNotAvailableAtOutletException;
use App\Exceptions\PurchaseOrderInvalidStateException;
use App\Exceptions\PurchaseReceiptOverQuantityException;
use App\Exceptions\RecipeCycleDetectedException;
use App\Exceptions\RecipeNotConfiguredException;
use App\Exceptions\RecipeVersionInvalidStateException;
use App\Exceptions\SaleInvalidStateException;
use App\Exceptions\ShiftClosedException;
use App\Exceptions\SubscriptionFeatureNotIncludedException;
use App\Exceptions\SubscriptionInactiveException;
use App\Exceptions\SubscriptionQuotaExceededException;
use App\Exceptions\SupplierInactiveException;
use App\Exceptions\TableUnavailableException;
use App\Exceptions\TransferInvalidStateException;
use App\Exceptions\UnsupportedPaymentProviderException;
use App\Exceptions\WebhookSignatureInvalidException;
use App\Http\Middleware\AssignRequestCorrelationId;
use App\Http\Middleware\AuthorizeOutletAccess;
use App\Http\Middleware\CheckTenantPermission;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureIdempotentRequest;
use App\Http\Middleware\EnsurePlatformSuperadmin;
use App\Http\Middleware\ResolveTenantContext;
use App\Http\Responses\ApiResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api/v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $trustedProxies = env('TRUSTED_PROXIES', '*');
        $middleware->trustProxies(at: $trustedProxies === '*' ? '*' : explode(',', (string) $trustedProxies));

        // Global middleware for API routes
        $middleware->api(prepend: [
            AssignRequestCorrelationId::class,
        ]);

        // Named middleware aliases
        $middleware->alias([
            'tenant' => ResolveTenantContext::class,
            'outlet' => AuthorizeOutletAccess::class,
            'permission' => CheckTenantPermission::class,
            'idempotent' => EnsureIdempotentRequest::class,
            'active' => EnsureAccountIsActive::class,
            'platform.superadmin' => EnsurePlatformSuperadmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PriceNotConfiguredException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'No active price configured for this variant.',
                    code: 'PRICE_NOT_CONFIGURED',
                    status: 422
                );
            }
        });

        $exceptions->render(function (ProductNotAvailableAtOutletException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Product is not available at this outlet.',
                    code: 'PRODUCT_NOT_AVAILABLE_AT_OUTLET',
                    status: 422
                );
            }
        });

        $exceptions->render(function (InsufficientStockException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Insufficient stock for this inventory item.',
                    code: 'INSUFFICIENT_STOCK',
                    status: 422
                );
            }
        });

        $exceptions->render(function (OpnameAlreadyApprovedException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'This stock opname has already been approved.',
                    code: 'OPNAME_ALREADY_APPROVED',
                    status: 422
                );
            }
        });

        $exceptions->render(function (TransferInvalidStateException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Invalid state for this transfer operation.',
                    code: 'TRANSFER_INVALID_STATE',
                    status: 422
                );
            }
        });

        $exceptions->render(function (PurchaseReceiptOverQuantityException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Received quantity exceeds the remaining ordered quantity.',
                    code: 'PURCHASE_RECEIPT_OVER_QUANTITY',
                    status: 422
                );
            }
        });

        $exceptions->render(function (PurchaseOrderInvalidStateException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Purchase order is in an invalid state for this operation.',
                    code: 'PURCHASE_ORDER_INVALID_STATE',
                    status: 422
                );
            }
        });

        $exceptions->render(function (SupplierInactiveException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Cannot create purchase order with inactive supplier.',
                    code: 'SUPPLIER_INACTIVE',
                    status: 422
                );
            }
        });

        $exceptions->render(function (RecipeCycleDetectedException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Cyclic BOM graph detected.',
                    code: 'RECIPE_CYCLE_DETECTED',
                    status: 422
                );
            }
        });

        $exceptions->render(function (RecipeNotConfiguredException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'No published recipe configured for this product variant.',
                    code: 'RECIPE_NOT_CONFIGURED',
                    status: 404
                );
            }
        });

        $exceptions->render(function (RecipeVersionInvalidStateException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Recipe version is in an invalid state for this operation.',
                    code: 'RECIPE_VERSION_NOT_ACTIVE',
                    status: 422
                );
            }
        });

        $exceptions->render(function (SaleInvalidStateException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Sale is in an invalid state for this operation.',
                    code: 'SALE_INVALID_STATE',
                    status: 422
                );
            }
        });

        $exceptions->render(function (InventoryLocationOutletMismatchException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Inventory location does not belong to the transaction outlet.',
                    code: 'INVENTORY_LOCATION_OUTLET_MISMATCH',
                    status: 422
                );
            }
        });

        $exceptions->render(function (NoPosInventoryLocationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'No active inventory location configured for this outlet.',
                    code: 'NO_POS_INVENTORY_LOCATION_FOR_OUTLET',
                    status: 422
                );
            }
        });

        $exceptions->render(function (PaymentInvalidStateException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Payment is in an invalid state for this operation.',
                    code: 'PAYMENT_INVALID_STATE',
                    status: 422
                );
            }
        });

        $exceptions->render(function (PaymentAmountExceedsOutstandingException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Payment amount exceeds current sale outstanding balance.',
                    code: 'PAYMENT_EXCEEDS_OUTSTANDING',
                    status: 422
                );
            }
        });

        $exceptions->render(function (PaymentSaleNotFinalizedException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Payment can only be accepted for finalized sales.',
                    code: 'PAYMENT_SALE_NOT_FINALIZED',
                    status: 422
                );
            }
        });

        $exceptions->render(function (PaymentRefundExceedsPaidException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Refund amount exceeds refundable payment amount.',
                    code: 'PAYMENT_REFUND_EXCEEDS_PAID',
                    status: 422
                );
            }
        });

        $exceptions->render(function (WebhookSignatureInvalidException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Invalid webhook signature.',
                    code: 'WEBHOOK_SIGNATURE_INVALID',
                    status: 400
                );
            }
        });

        $exceptions->render(function (PaymentWebhookAmountMismatchException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Webhook payment amount does not match internal payment record.',
                    code: 'PAYMENT_WEBHOOK_AMOUNT_MISMATCH',
                    status: 400
                );
            }
        });

        $exceptions->render(function (PaymentReconciliationNotFoundException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Payment reconciliation record not found.',
                    code: 'PAYMENT_RECONCILIATION_NOT_FOUND',
                    status: 404
                );
            }
        });

        $exceptions->render(function (PaymentReconciliationInvalidStateException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Invalid payment reconciliation state.',
                    code: 'PAYMENT_RECONCILIATION_INVALID_STATE',
                    status: 422
                );
            }
        });

        $exceptions->render(function (PaymentWebhookCurrencyMismatchException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Webhook payment currency does not match internal payment record.',
                    code: 'PAYMENT_WEBHOOK_CURRENCY_MISMATCH',
                    status: 400
                );
            }
        });

        $exceptions->render(function (UnsupportedPaymentProviderException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Payment provider is unsupported.',
                    code: 'UNSUPPORTED_PAYMENT_PROVIDER',
                    status: 400
                );
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: 'Validation failed.',
                    code: 'VALIDATION_ERROR',
                    errors: $e->errors(),
                    status: 422
                );
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: 'Unauthenticated.',
                    code: 'UNAUTHENTICATED',
                    status: 401
                );
            }
        });

        $exceptions->render(function (ActiveShiftAlreadyExistsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage(),
                    code: 'ACTIVE_SHIFT_ALREADY_EXISTS',
                    status: 409
                );
            }
        });

        $exceptions->render(function (ShiftClosedException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage(),
                    code: 'SHIFT_CLOSED',
                    status: 409
                );
            }
        });

        $exceptions->render(function (IdempotencyReplayUnavailableException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Tracking credential replay is unavailable for this request.',
                    code: 'IDEMPOTENCY_REPLAY_UNAVAILABLE',
                    status: 409
                );
            }
        });

        $exceptions->render(function (InsufficientDrawerCashException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage(),
                    code: 'INSUFFICIENT_DRAWER_CASH',
                    status: 422
                );
            }
        });

        $exceptions->render(function (SubscriptionFeatureNotIncludedException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Feature not included in current subscription plan.',
                    code: 'SUBSCRIPTION_FEATURE_NOT_INCLUDED',
                    status: 403
                );
            }
        });

        $exceptions->render(function (SubscriptionQuotaExceededException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Subscription quota limit exceeded for this resource.',
                    code: 'SUBSCRIPTION_QUOTA_EXCEEDED',
                    status: 422
                );
            }
        });

        $exceptions->render(function (SubscriptionInactiveException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Subscription is inactive, expired, or past due.',
                    code: 'SUBSCRIPTION_INACTIVE',
                    status: 403
                );
            }
        });

        $exceptions->render(function (BookingOverlapException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Table is already booked during this time interval.',
                    code: 'BOOKING_OVERLAP',
                    status: 422
                );
            }
        });

        $exceptions->render(function (TableUnavailableException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Table is currently occupied or inactive.',
                    code: 'TABLE_UNAVAILABLE',
                    status: 422
                );
            }
        });

        $exceptions->render(function (OrderInvalidStateException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Order is in an invalid state for this operation.',
                    code: 'ORDER_INVALID_STATE',
                    status: 422
                );
            }
        });

        $exceptions->render(function (InsufficientLoyaltyPointsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Poin loyalitas tidak mencukupi.',
                    code: 'INSUFFICIENT_LOYALTY_POINTS',
                    status: 422
                );
            }
        });

        $exceptions->render(function (LoyaltyAccountInactiveException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Akun loyalitas sedang tidak aktif.',
                    code: 'LOYALTY_ACCOUNT_INACTIVE',
                    status: 422
                );
            }
        });

        $exceptions->render(function (InvalidArgumentException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'Invalid argument provided.',
                    code: 'VALIDATION_ERROR',
                    status: 422
                );
            }
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'This action is unauthorized.',
                    code: 'FORBIDDEN',
                    status: 403
                );
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') && $e->getStatusCode() === 403) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: 'This action is unauthorized.',
                    code: 'FORBIDDEN',
                    status: 403
                );
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: 'The requested resource was not found.',
                    code: 'NOT_FOUND',
                    status: 404
                );
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            if ($request->is('api/*')) {
                return ApiResponse::error(
                    message: 'Too many requests. Please slow down.',
                    code: 'RATE_LIMIT_EXCEEDED',
                    status: 429
                );
            }
        });

        // Global fallback: prevent SQL, filesystem, and trace leakages in production
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                $isDebug = config('app.debug', false);

                return ApiResponse::error(
                    message: $isDebug ? $e->getMessage() : 'An unexpected server error occurred.',
                    code: 'INTERNAL_SERVER_ERROR',
                    errors: $isDebug ? [
                        'exception' => get_class($e),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ] : [],
                    status: 500
                );
            }
        });
    })->create();
