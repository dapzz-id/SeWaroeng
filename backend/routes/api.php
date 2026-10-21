<?php

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Api\V1\AiCopilotController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BookingController;
use App\Http\Controllers\Api\V1\CategoryController;
use App\Http\Controllers\Api\V1\CustomerController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\EmployeeController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\FinanceExportController;
use App\Http\Controllers\Api\V1\FinanceReportController;
use App\Http\Controllers\Api\V1\InventoryAdjustmentController;
use App\Http\Controllers\Api\V1\InventoryItemController;
use App\Http\Controllers\Api\V1\InventoryLocationController;
use App\Http\Controllers\Api\V1\LoyaltyController;
use App\Http\Controllers\Api\V1\ModifierGroupController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\OutletController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentReconciliationController;
use App\Http\Controllers\Api\V1\Platform\PaymentProviderController;
use App\Http\Controllers\Api\V1\Platform\SubscriptionPlanController;
use App\Http\Controllers\Api\V1\PosController;
use App\Http\Controllers\Api\V1\PriceController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ProductVariantController;
use App\Http\Controllers\Api\V1\PublicTableController;
use App\Http\Controllers\Api\V1\PurchaseOrderController;
use App\Http\Controllers\Api\V1\PurchaseReceiptController;
use App\Http\Controllers\Api\V1\ReceiptController;
use App\Http\Controllers\Api\V1\RecipeController;
use App\Http\Controllers\Api\V1\ShiftController;
use App\Http\Controllers\Api\V1\StockOpnameController;
use App\Http\Controllers\Api\V1\StockTransferController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\SupplierController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\TableController;
use App\Http\Controllers\Api\V1\TableSessionController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\WhatsAppConnectionController;
use App\Http\Controllers\Api\V1\WhatsAppMessageController;
use App\Http\Controllers\Api\V1\WhatsAppWebhookController;
use App\Http\Responses\ApiResponse;
use App\Models\Outlet;
use App\Models\TenantTestRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - /api/v1
|--------------------------------------------------------------------------
*/

// Public Health & Readiness Probe (Safe operational status only - zero secrets or traces leaked)
Route::get('/health', function () {
    $dbHealthy = false;
    $redisHealthy = true;

    try {
        DB::connection()->getPdo();
        $dbHealthy = true;
    } catch (Throwable $e) {
        // Safe failure: no connection strings, credentials, or exception traces are leaked
    }

    $redisConfigured = in_array('redis', [
        config('cache.default'),
        config('session.driver'),
        config('queue.default'),
    ], true);

    if ($redisConfigured) {
        try {
            Redis::connection()->ping();
            $redisHealthy = true;
        } catch (Throwable $e) {
            $redisHealthy = false;
        }
    }

    $isHealthy = $dbHealthy && $redisHealthy;

    return ApiResponse::success(
        data: [
            'status' => $isHealthy ? 'ok' : 'degraded',
            'timestamp' => now()->toIso8601String(),
        ],
        status: $isHealthy ? 200 : 503
    );
});

// Identity & Authentication Routes
Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register']);
    Route::post('/login', [AuthController::class, 'login']);

    Route::middleware(['auth:sanctum', 'active'])->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::get('/sessions', [AuthController::class, 'sessions']);
        Route::delete('/sessions/{id}', [AuthController::class, 'revokeSession']);
    });
});

// Public Customer QR Table Ordering Routes (V1)
Route::prefix('public/tables')->group(function () {
    Route::middleware(['throttle:60,1,public_table_read'])->group(function () {
        Route::get('/{token}', [PublicTableController::class, 'show']);
        Route::get('/{token}/menu', [PublicTableController::class, 'menu']);
        Route::get('/{token}/orders/{trackingToken}', [PublicTableController::class, 'orderStatus']);
    });
    Route::post('/{token}/orders', [PublicTableController::class, 'submitOrder'])
        ->middleware(['throttle:30,1,public_table_order']);
});

// Authenticated User Profile with Memberships
Route::middleware(['auth:sanctum', 'active'])->get('/me', function (Request $request) {
    $user = $request->user()->load(['memberships.tenant', 'memberships.role']);

    return ApiResponse::success(data: [
        'id' => $user->id,
        'name' => $user->name,
        'email' => $user->email,
        'phone_number' => $user->phone_number,
        'is_active' => $user->is_active,
        'memberships' => $user->memberships->map(fn ($m) => [
            'id' => $m->id,
            'tenant_id' => $m->tenant_id,
            'tenant_name' => $m->tenant->name,
            'role' => $m->role->slug,
            'outlet_access' => $m->outlet_access,
            'assigned_outlet_ids' => $m->assigned_outlet_ids,
        ]),
    ]);
});

// Business / Tenant Management Routes (Phase 1A.2)
Route::middleware(['auth:sanctum', 'active'])->prefix('tenants')->group(function () {
    Route::post('/', [TenantController::class, 'store'])->middleware('idempotent');
    Route::get('/', [TenantController::class, 'index']);
    Route::get('/{tenant}', [TenantController::class, 'show']);
    Route::patch('/{tenant}', [TenantController::class, 'update'])->middleware('idempotent');
});

// Tenant-Scoped Outlet Management Routes (Phase 1A.2)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('outlets')->group(function () {
    Route::get('/', [OutletController::class, 'index']);
    Route::get('/{outletId}', [OutletController::class, 'show'])->middleware('outlet:outletId');
    Route::post('/', [OutletController::class, 'store'])->middleware('idempotent');
    Route::patch('/{outletId}', [OutletController::class, 'update'])->middleware(['outlet:outletId', 'idempotent']);
});

// Employee & Staff Management Routes (Phase 1A.3 / PRD #16)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('employees')->group(function () {
    Route::get('/roles', [EmployeeController::class, 'roles'])->middleware('permission:employee.manage');
    Route::get('/', [EmployeeController::class, 'index'])->middleware('permission:employee.manage');
    Route::post('/', [EmployeeController::class, 'store'])->middleware(['permission:employee.manage', 'idempotent']);
    Route::get('/{id}', [EmployeeController::class, 'show'])->middleware('permission:employee.manage');
    Route::patch('/{id}', [EmployeeController::class, 'update'])->middleware(['permission:employee.manage', 'idempotent']);
    Route::delete('/{id}', [EmployeeController::class, 'destroy'])->middleware(['permission:employee.manage']);
});

// Catalog Management Routes (Phase 1B.1)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->group(function () {

    // Categories
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index'])->middleware('permission:catalog.view');
        Route::get('/{category}', [CategoryController::class, 'show'])->middleware('permission:catalog.view');
        Route::post('/', [CategoryController::class, 'store'])->middleware(['permission:catalog.category.manage', 'idempotent']);
        Route::patch('/{category}', [CategoryController::class, 'update'])->middleware(['permission:catalog.category.manage', 'idempotent']);
    });

    // Products
    Route::prefix('products')->group(function () {
        Route::get('/', [ProductController::class, 'index'])->middleware('permission:catalog.view');
        Route::get('/{product}', [ProductController::class, 'show'])->middleware('permission:catalog.view');
        Route::post('/', [ProductController::class, 'store'])->middleware(['permission:catalog.create', 'idempotent']);
        Route::patch('/{product}', [ProductController::class, 'update'])->middleware(['permission:catalog.update', 'idempotent']);
        Route::post('/{product}/availability', [ProductController::class, 'setOutletAvailability'])->middleware(['permission:catalog.update', 'idempotent']);

        // Variants under Products
        Route::get('/{product}/variants', [ProductVariantController::class, 'index'])->middleware('permission:catalog.view');
        Route::post('/{product}/variants', [ProductVariantController::class, 'store'])->middleware(['permission:catalog.update', 'idempotent']);
        Route::patch('/{product}/variants/{variant}', [ProductVariantController::class, 'update'])->middleware(['permission:catalog.update', 'idempotent']);
        Route::post('/{product}/variants/{variant}/barcodes', [ProductVariantController::class, 'addBarcode'])->middleware(['permission:catalog.update', 'idempotent']);
        Route::delete('/{product}/variants/{variant}/barcodes/{barcode}', [ProductVariantController::class, 'removeBarcode'])->middleware(['permission:catalog.update', 'idempotent']);
    });

    // Prices (Dedicated Pricing Boundary)
    Route::prefix('prices')->group(function () {
        Route::get('/resolve', [PriceController::class, 'resolve'])->middleware('permission:catalog.view');
        Route::get('/', [PriceController::class, 'index'])->middleware('permission:catalog.view');
        Route::post('/', [PriceController::class, 'store'])->middleware(['permission:catalog.price.update', 'idempotent']);
        Route::patch('/{price}', [PriceController::class, 'update'])->middleware(['permission:catalog.price.update', 'idempotent']);
    });

    // Modifier Groups & Modifiers
    Route::prefix('modifier-groups')->group(function () {
        Route::get('/', [ModifierGroupController::class, 'index'])->middleware('permission:catalog.view');
        Route::post('/', [ModifierGroupController::class, 'store'])->middleware(['permission:catalog.modifier.manage', 'idempotent']);
        Route::patch('/{group}', [ModifierGroupController::class, 'update'])->middleware(['permission:catalog.modifier.manage', 'idempotent']);
        Route::post('/{group}/modifiers', [ModifierGroupController::class, 'addModifier'])->middleware(['permission:catalog.modifier.manage', 'idempotent']);
        Route::patch('/{group}/modifiers/{modifier}', [ModifierGroupController::class, 'updateModifier'])->middleware(['permission:catalog.modifier.manage', 'idempotent']);
    });
});

// Inventory Management Routes (Phase 1B.2)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('inventory')->group(function () {

    // Locations
    Route::prefix('locations')->group(function () {
        Route::get('/', [InventoryLocationController::class, 'index'])->middleware('permission:inventory.view');
        Route::post('/', [InventoryLocationController::class, 'store'])->middleware(['permission:inventory.location.manage', 'idempotent']);
        Route::get('/{location}', [InventoryLocationController::class, 'show'])->middleware('permission:inventory.view');
    });

    // Items, Balances, & Movements
    Route::prefix('items')->group(function () {
        Route::get('/', [InventoryItemController::class, 'index'])->middleware('permission:inventory.view');
        Route::post('/', [InventoryItemController::class, 'store'])->middleware(['permission:inventory.adjust', 'idempotent']);
        Route::get('/{item}', [InventoryItemController::class, 'show'])->middleware('permission:inventory.view');
        Route::get('/{item}/movements', [InventoryItemController::class, 'movements'])->middleware('permission:inventory.view');
        Route::post('/{item}/threshold', [InventoryItemController::class, 'updateThreshold'])->middleware(['permission:inventory.threshold.manage', 'idempotent']);
    });

    // Bulk Availability Query
    Route::get('/availability', [InventoryItemController::class, 'availability'])->middleware('permission:inventory.view');

    // Adjustments & Opening Balances
    Route::post('/opening-balances', [InventoryAdjustmentController::class, 'storeOpeningBalance'])->middleware(['permission:inventory.adjust', 'idempotent']);
    Route::post('/adjustments', [InventoryAdjustmentController::class, 'storeAdjustment'])->middleware(['permission:inventory.adjust', 'idempotent']);

    // Stock Opname
    Route::prefix('opnames')->group(function () {
        Route::get('/', [StockOpnameController::class, 'index'])->middleware('permission:inventory.opname.review');
        Route::post('/', [StockOpnameController::class, 'store'])->middleware(['permission:inventory.opname.create', 'idempotent']);
        Route::get('/{opname}', [StockOpnameController::class, 'show'])->middleware('permission:inventory.opname.review');
        Route::post('/{opname}/approve', [StockOpnameController::class, 'approve'])->middleware(['permission:inventory.opname.approve', 'idempotent']);
    });

    // Stock Transfers
    Route::prefix('transfers')->group(function () {
        Route::get('/', [StockTransferController::class, 'index'])->middleware('permission:inventory.view');
        Route::post('/', [StockTransferController::class, 'store'])->middleware(['permission:inventory.transfer.create', 'idempotent']);
        Route::get('/{transfer}', [StockTransferController::class, 'show'])->middleware('permission:inventory.view');
        Route::post('/{transfer}/dispatch', [StockTransferController::class, 'dispatch'])->middleware(['permission:inventory.transfer.approve', 'idempotent']);
        Route::post('/{transfer}/receive', [StockTransferController::class, 'receive'])->middleware(['permission:inventory.transfer.receive', 'idempotent']);
    });
});

// Purchasing & Supplier Foundation Routes (Phase 1B.3)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('purchasing')->group(function () {

    // Suppliers
    Route::prefix('suppliers')->group(function () {
        Route::get('/', [SupplierController::class, 'index'])->middleware('permission:purchasing.view');
        Route::post('/', [SupplierController::class, 'store'])->middleware(['permission:purchasing.supplier.manage', 'idempotent']);
        Route::get('/{supplier}', [SupplierController::class, 'show'])->middleware('permission:purchasing.view');
        Route::put('/{supplier}', [SupplierController::class, 'update'])->middleware(['permission:purchasing.supplier.manage', 'idempotent']);
    });

    // Smart Reorder Recommendations (PRD #13)
    Route::get('/smart-reorder', [PurchaseOrderController::class, 'smartReorder'])->middleware('permission:purchasing.view');

    // Purchase Orders
    Route::prefix('orders')->group(function () {
        Route::get('/', [PurchaseOrderController::class, 'index'])->middleware('permission:purchasing.view');
        Route::post('/', [PurchaseOrderController::class, 'store'])->middleware(['permission:purchasing.create', 'idempotent']);
        Route::get('/{order}', [PurchaseOrderController::class, 'show'])->middleware('permission:purchasing.view');
        Route::post('/{order}/submit', [PurchaseOrderController::class, 'submit'])->middleware(['permission:purchasing.submit', 'idempotent']);
        Route::post('/{order}/approve', [PurchaseOrderController::class, 'approve'])->middleware(['permission:purchasing.approve', 'idempotent']);
        Route::post('/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->middleware(['permission:purchasing.cancel', 'idempotent']);
    });

    // Purchase Receipts (Goods Receipt Notes)
    Route::prefix('receipts')->group(function () {
        Route::get('/', [PurchaseReceiptController::class, 'index'])->middleware('permission:purchasing.view');
        Route::post('/', [PurchaseReceiptController::class, 'store'])->middleware(['permission:purchasing.receive', 'idempotent']);
        Route::get('/{receipt}', [PurchaseReceiptController::class, 'show'])->middleware('permission:purchasing.view');
        Route::post('/{receipt}/post', [PurchaseReceiptController::class, 'post'])->middleware(['permission:purchasing.receive', 'idempotent']);
        Route::post('/{receipt}/cancel', [PurchaseReceiptController::class, 'cancel'])->middleware(['permission:purchasing.cancel', 'idempotent']);
    });
});

// Recipe / BOM & Costing Routes (Phase 1B.4)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('recipes')->group(function () {
    Route::get('/', [RecipeController::class, 'index'])->middleware('permission:recipe.view');
    Route::post('/', [RecipeController::class, 'store'])->middleware(['permission:recipe.create', 'idempotent']);
    Route::get('/{recipe}', [RecipeController::class, 'show'])->middleware('permission:recipe.view');
    Route::put('/{recipe}', [RecipeController::class, 'update'])->middleware(['permission:recipe.update', 'idempotent']);

    // Versions
    Route::get('/{recipe}/versions', [RecipeController::class, 'indexVersions'])->middleware('permission:recipe.view');
    Route::post('/{recipe}/versions', [RecipeController::class, 'storeVersion'])->middleware(['permission:recipe.create', 'idempotent']);
    Route::get('/{recipe}/versions/{version}', [RecipeController::class, 'showVersion'])->middleware('permission:recipe.view');
    Route::post('/{recipe}/versions/{version}/components', [RecipeController::class, 'setVersionComponents'])->middleware(['permission:recipe.update', 'idempotent']);
    Route::post('/{recipe}/versions/{version}/publish', [RecipeController::class, 'publishVersion'])->middleware(['permission:recipe.publish', 'idempotent']);
    Route::post('/{recipe}/versions/{version}/archive', [RecipeController::class, 'archiveVersion'])->middleware(['permission:recipe.archive', 'idempotent']);

    // Resolution, Costing & Explosion (read-only)
    Route::get('/{recipe}/resolve', [RecipeController::class, 'resolve'])->middleware('permission:recipe.view');
    Route::get('/{recipe}/cost', [RecipeController::class, 'cost'])->middleware('permission:recipe.cost.view');
    Route::get('/{recipe}/explode', [RecipeController::class, 'explode'])->middleware('permission:recipe.view');
});

// POS / Sales Transaction Core Routes (Phase 1C)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('pos')->group(function () {
    Route::get('/catalog', [PosController::class, 'catalog'])->middleware('permission:pos.view');

    Route::prefix('sales')->group(function () {
        Route::get('/', [PosController::class, 'index'])->middleware('permission:pos.view');
        Route::post('/', [PosController::class, 'store'])->middleware(['permission:pos.create', 'idempotent']);
        Route::get('/{sale}', [PosController::class, 'show'])->middleware('permission:pos.view');
        Route::put('/{sale}', [PosController::class, 'update'])->middleware(['permission:pos.create', 'idempotent']);
        Route::post('/{sale}/finalize', [PosController::class, 'finalize'])->middleware(['permission:pos.finalize', 'idempotent']);
        Route::post('/{sale}/apply-voucher', [PosController::class, 'applyVoucher'])->middleware(['permission:pos.discount', 'idempotent']);
        Route::delete('/{sale}/voucher', [PosController::class, 'removeVoucher'])->middleware(['permission:pos.discount', 'idempotent']);
        Route::post('/{sale}/cancel', [PosController::class, 'cancel'])->middleware(['permission:pos.cancel', 'idempotent']);
        Route::get('/{sale}/receipt', [ReceiptController::class, 'show'])->middleware('permission:receipt.view');
        Route::post('/{sale}/receipt/reprint', [ReceiptController::class, 'reprint'])->middleware(['permission:receipt.reprint', 'idempotent']);
    });
});

// Offline POS Sync Routes (Phase 1I)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('sync')->group(function () {
    Route::post('/', [SyncController::class, 'sync'])->middleware('permission:pos.create');
});

// Customer & CRM Routes (Phase 1F)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('customers')->group(function () {
    Route::get('/', [CustomerController::class, 'index'])->middleware('permission:customer.view');
    Route::post('/', [CustomerController::class, 'store'])->middleware(['permission:customer.create', 'idempotent']);
    Route::get('/{customer}', [CustomerController::class, 'show'])->middleware('permission:customer.view');
    Route::put('/{customer}', [CustomerController::class, 'update'])->middleware(['permission:customer.update', 'idempotent']);
    Route::patch('/{customer}', [CustomerController::class, 'update'])->middleware(['permission:customer.update', 'idempotent']);
    Route::get('/{customer}/history', [CustomerController::class, 'history'])->middleware('permission:customer.history.view');
    Route::get('/{customer}/summary', [CustomerController::class, 'summary'])->middleware('permission:customer.view');
});

// Payment & Settlement Routes (Phase 1D)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->group(function () {
    Route::prefix('payments')->group(function () {
        Route::get('/', [PaymentController::class, 'index'])->middleware('permission:payment.view');
        Route::post('/', [PaymentController::class, 'store'])->middleware(['permission:payment.create', 'idempotent']);
        Route::get('/{payment}', [PaymentController::class, 'show'])->middleware('permission:payment.view');
        Route::post('/{payment}/process', [PaymentController::class, 'process'])->middleware(['permission:payment.process', 'idempotent']);
        Route::post('/{payment}/sync-status', [PaymentController::class, 'syncStatus'])->middleware(['permission:payment.process', 'idempotent']);
        Route::post('/{payment}/cancel', [PaymentController::class, 'cancel'])->middleware(['permission:payment.cancel', 'idempotent']);
        Route::post('/{payment}/refund', [PaymentController::class, 'refund'])->middleware(['permission:payment.refund', 'idempotent']);
    });

    Route::get('/sales/{sale}/payments', [PaymentController::class, 'salePayments'])->middleware('permission:payment.view');

    // Payment Reconciliation Routes (V1 Payment Reconciliation)
    Route::prefix('payment-reconciliation')->group(function () {
        Route::get('/', [PaymentReconciliationController::class, 'index'])->middleware('permission:payment.reconcile');
        Route::get('/summary', [PaymentReconciliationController::class, 'summary'])->middleware('permission:payment.reconcile');
        Route::get('/{id}', [PaymentReconciliationController::class, 'show'])->middleware('permission:payment.reconcile');
        Route::post('/reconcile-payment', [PaymentReconciliationController::class, 'reconcilePayment'])->middleware(['permission:payment.reconcile', 'idempotent']);
        Route::post('/import', [PaymentReconciliationController::class, 'importBatch'])->middleware(['permission:payment.reconcile', 'idempotent']);
        Route::post('/{id}/resolve', [PaymentReconciliationController::class, 'resolveDiscrepancy'])->middleware(['permission:payment.reconcile', 'idempotent']);
    });

    // Shift & Cash Drawer Management Routes (Phase 1F)
    Route::prefix('outlets/{outletId}/shifts')->middleware('outlet:outletId')->group(function () {
        Route::get('/', [ShiftController::class, 'index'])->middleware('permission:shift.view');
        Route::post('/open', [ShiftController::class, 'open'])->middleware(['permission:shift.open', 'idempotent']);
        Route::get('/current', [ShiftController::class, 'current'])->middleware('permission:shift.view');
    });

    Route::prefix('shifts/{shiftId}')->group(function () {
        Route::get('/summary', [ShiftController::class, 'summary'])->middleware('permission:cash.view');
        Route::post('/cash-in', [ShiftController::class, 'cashIn'])->middleware(['permission:cash.in', 'idempotent']);
        Route::post('/cash-out', [ShiftController::class, 'cashOut'])->middleware(['permission:cash.out', 'idempotent']);
        Route::post('/adjust', [ShiftController::class, 'adjust'])->middleware(['permission:cash.adjust', 'idempotent']);
        Route::post('/close', [ShiftController::class, 'close'])->middleware(['permission:shift.close', 'idempotent']);
    });
});

// Basic Finance, Expense, Cash Flow & P&L Routes (Phase 1G)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('finance')->group(function () {
    // Expense Management
    Route::prefix('expenses')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->middleware('permission:finance.expense.view');
        Route::post('/', [ExpenseController::class, 'store'])->middleware(['permission:finance.expense.create', 'idempotent']);
        Route::get('/{id}', [ExpenseController::class, 'show'])->middleware('permission:finance.expense.view');
        Route::post('/{id}/post', [ExpenseController::class, 'post'])->middleware(['permission:finance.expense.post', 'idempotent']);
        Route::post('/{id}/reverse', [ExpenseController::class, 'reverse'])->middleware(['permission:finance.expense.reverse', 'idempotent']);
    });

    // Reports & Ledger
    Route::get('/profit-loss', [FinanceReportController::class, 'profitAndLoss'])->middleware('permission:finance.report.view');
    Route::get('/profit-loss/export', [FinanceExportController::class, 'profitAndLoss'])->middleware('permission:finance.report.view');
    Route::get('/cash-flow', [FinanceReportController::class, 'cashFlow'])->middleware('permission:finance.report.view');
    Route::get('/cash-flow/export', [FinanceExportController::class, 'cashFlow'])->middleware('permission:finance.report.view');
    Route::get('/balance-sheet', [FinanceReportController::class, 'balanceSheet'])->middleware('permission:finance.report.view');
    Route::get('/balance-sheet/export', [FinanceExportController::class, 'balanceSheet'])->middleware('permission:finance.report.view');
    Route::get('/trial-balance', [FinanceReportController::class, 'trialBalance'])->middleware('permission:finance.report.view');
    Route::get('/trial-balance/export', [FinanceExportController::class, 'trialBalance'])->middleware('permission:finance.report.view');
    Route::get('/account-ledger', [FinanceReportController::class, 'accountLedger'])->middleware('permission:finance.report.view');
    Route::get('/account-ledger/export', [FinanceExportController::class, 'accountLedger'])->middleware('permission:finance.report.view');
    Route::get('/clearing-summary', [FinanceReportController::class, 'clearingSummary'])->middleware('permission:finance.report.view');
    Route::get('/clearing-summary/export', [FinanceExportController::class, 'clearingSummary'])->middleware('permission:finance.report.view');
    Route::get('/journal-entries', [FinanceReportController::class, 'journalEntries'])->middleware('permission:finance.report.view');
    Route::get('/journal-entries/export', [FinanceExportController::class, 'journalEntries'])->middleware('permission:finance.report.view');
    Route::get('/accounts', [FinanceReportController::class, 'accounts'])->middleware('permission:finance.expense.view');

    // Canonical PRD /reports prefix aliases
    Route::prefix('reports')->group(function () {
        Route::get('/profit-and-loss', [FinanceReportController::class, 'profitAndLoss'])->middleware('permission:finance.report.view');
        Route::get('/profit-and-loss/export', [FinanceExportController::class, 'profitAndLoss'])->middleware('permission:finance.report.view');
        Route::get('/cash-flow', [FinanceReportController::class, 'cashFlow'])->middleware('permission:finance.report.view');
        Route::get('/cash-flow/export', [FinanceExportController::class, 'cashFlow'])->middleware('permission:finance.report.view');
        Route::get('/balance-sheet', [FinanceReportController::class, 'balanceSheet'])->middleware('permission:finance.report.view');
        Route::get('/balance-sheet/export', [FinanceExportController::class, 'balanceSheet'])->middleware('permission:finance.report.view');
        Route::get('/trial-balance', [FinanceReportController::class, 'trialBalance'])->middleware('permission:finance.report.view');
        Route::get('/trial-balance/export', [FinanceExportController::class, 'trialBalance'])->middleware('permission:finance.report.view');
        Route::get('/account-ledger', [FinanceReportController::class, 'accountLedger'])->middleware('permission:finance.report.view');
        Route::get('/account-ledger/export', [FinanceExportController::class, 'accountLedger'])->middleware('permission:finance.report.view');
        Route::get('/clearing-summary', [FinanceReportController::class, 'clearingSummary'])->middleware('permission:finance.report.view');
        Route::get('/clearing-summary/export', [FinanceExportController::class, 'clearingSummary'])->middleware('permission:finance.report.view');
        Route::get('/journal-entries/export', [FinanceExportController::class, 'journalEntries'])->middleware('permission:finance.report.view');
        Route::get('/{report}/export', [FinanceExportController::class, 'export'])->middleware('permission:finance.report.view');
    });
});

// Notifications & Operational Alerts Routes (Phase 1H)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('notifications')->group(function () {
    Route::get('/', [NotificationController::class, 'index'])->middleware('permission:notification.view');
    Route::get('/unread-count', [NotificationController::class, 'unreadCount'])->middleware('permission:notification.view');
    Route::post('/{id}/read', [NotificationController::class, 'markRead'])->middleware('permission:notification.read');
    Route::post('/read-all', [NotificationController::class, 'markAllRead'])->middleware('permission:notification.read');
});

// Operational Dashboard Routes (Phase 1H)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('dashboard')->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->middleware('permission:dashboard.view');
});

// AI Business Copilot & Analytics Routes (Phase 2 / PRD #23)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('ai')->group(function () {
    Route::post('/copilot', [AiCopilotController::class, 'query'])->middleware(['permission:dashboard.view', 'idempotent']);
});

// Tables Domain (V1)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('tables')->group(function () {
    Route::get('/floor-plan', [TableController::class, 'getFloorPlan'])->middleware('permission:table.view');
    Route::post('/floor-plan', [TableController::class, 'saveFloorPlan'])->middleware(['permission:table.manage', 'idempotent']);
    Route::get('/', [TableController::class, 'index'])->middleware('permission:table.view');
    Route::post('/', [TableController::class, 'store'])->middleware(['permission:table.manage', 'idempotent']);
    Route::get('/{table}', [TableController::class, 'show'])->middleware('permission:table.view');
    Route::patch('/{table}', [TableController::class, 'update'])->middleware(['permission:table.manage', 'idempotent']);
    Route::post('/{table}/rotate-qr', [TableController::class, 'rotateQr'])->middleware(['permission:table.manage', 'idempotent']);
    Route::post('/{table}/revoke-qr', [TableController::class, 'revokeQr'])->middleware(['permission:table.manage', 'idempotent']);
    Route::post('/{table}/check-in', [TableController::class, 'checkIn'])->middleware(['permission:table.manage', 'idempotent']);
    Route::post('/{table}/release', [TableController::class, 'release'])->middleware(['permission:table.manage', 'idempotent']);
});

// Bookings Domain (V1)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('bookings')->group(function () {
    Route::get('/', [BookingController::class, 'index'])->middleware('permission:booking.view');
    Route::post('/', [BookingController::class, 'store'])->middleware(['permission:booking.manage', 'idempotent']);
    Route::get('/{booking}', [BookingController::class, 'show'])->middleware('permission:booking.view');
    Route::patch('/{booking}', [BookingController::class, 'update'])->middleware(['permission:booking.manage', 'idempotent']);
    Route::post('/{booking}/check-in', [BookingController::class, 'checkIn'])->middleware(['permission:booking.manage', 'idempotent']);
    Route::post('/{booking}/cancel', [BookingController::class, 'cancel'])->middleware(['permission:booking.manage', 'idempotent']);
});

// Orders Domain (V1)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('orders')->group(function () {
    Route::get('/', [OrderController::class, 'index'])->middleware('permission:order.view');
    Route::get('/{order}', [OrderController::class, 'show'])->middleware('permission:order.view');
    Route::patch('/{order}/status', [OrderController::class, 'updateStatus'])->middleware(['permission:order.manage', 'idempotent']);
    Route::post('/convert-to-sale', [OrderController::class, 'convertToSale'])->middleware(['permission:order.manage', 'idempotent']);
});

// Table Sessions Domain (V1)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('table-sessions')->group(function () {
    Route::get('/', [TableSessionController::class, 'index'])->middleware('permission:table.view');
    Route::post('/', [TableSessionController::class, 'store'])->middleware(['permission:table.manage', 'idempotent']);
    Route::post('/{session}/checkout', [TableSessionController::class, 'checkout'])->middleware(['permission:table.manage', 'idempotent']);
});

// Loyalty Domain (V1)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('loyalty')->group(function () {
    // Program configuration
    Route::get('/program', [LoyaltyController::class, 'showProgram'])->middleware('permission:loyalty.view');
    Route::post('/program', [LoyaltyController::class, 'updateProgram'])->middleware(['permission:loyalty.manage', 'idempotent']);
    Route::put('/program', [LoyaltyController::class, 'updateProgram'])->middleware(['permission:loyalty.manage', 'idempotent']);

    // Tiers
    Route::get('/tiers', [LoyaltyController::class, 'listTiers'])->middleware('permission:loyalty.view');
    Route::post('/tiers', [LoyaltyController::class, 'storeTier'])->middleware(['permission:loyalty.manage', 'idempotent']);
    Route::put('/tiers/{tier}', [LoyaltyController::class, 'updateTier'])->middleware(['permission:loyalty.manage', 'idempotent']);

    // Rewards
    Route::get('/rewards', [LoyaltyController::class, 'listRewards'])->middleware('permission:loyalty.view');
    Route::post('/rewards', [LoyaltyController::class, 'storeReward'])->middleware(['permission:loyalty.manage', 'idempotent']);
    Route::put('/rewards/{reward}', [LoyaltyController::class, 'updateReward'])->middleware(['permission:loyalty.manage', 'idempotent']);

    // Customer Loyalty Accounts & Operations
    Route::prefix('customers/{customer}')->group(function () {
        Route::get('/account', [LoyaltyController::class, 'showCustomerAccount'])->middleware('permission:loyalty.view');
        Route::get('/ledger', [LoyaltyController::class, 'listCustomerLedger'])->middleware('permission:loyalty.view');
        Route::post('/adjust', [LoyaltyController::class, 'adjustCustomerPoints'])->middleware(['permission:loyalty.adjust', 'idempotent']);
        Route::post('/redeem', [LoyaltyController::class, 'redeemReward'])->middleware(['permission:loyalty.redeem', 'idempotent']);
        Route::get('/vouchers', [LoyaltyController::class, 'listCustomerVouchers'])->middleware('permission:loyalty.view');
    });

    // POS Voucher Validation (Server-Authoritative Discount Rule)
    Route::post('/vouchers/validate', [LoyaltyController::class, 'validateVoucher'])->middleware(['permission:pos.sell']);

    // Audit Balance Reconciliation
    Route::get('/reconcile/{account}', [LoyaltyController::class, 'reconcileAccount'])->middleware('permission:loyalty.report');
});

// Payment Webhook Ingestion (Public provider callback with signature verification & rate limiting)
Route::post('/payments/webhooks/{provider}', [PaymentController::class, 'handleWebhook'])->middleware('throttle:60,1');

// WhatsApp Webhook Ingestion (Platform & Tenant-Scoped callback with verification & signature checking)
Route::get('/webhooks/whatsapp/{provider}', [WhatsAppWebhookController::class, 'verifyPlatformChallenge']);
Route::post('/webhooks/whatsapp/{provider}', [WhatsAppWebhookController::class, 'handlePlatformWebhook'])->middleware('throttle:120,1');
Route::get('/webhooks/whatsapp/{tenant}/{provider}', [WhatsAppWebhookController::class, 'verifyChallenge']);
Route::post('/webhooks/whatsapp/{tenant}/{provider}', [WhatsAppWebhookController::class, 'handleWebhook'])->middleware('throttle:120,1');

// Superadmin Platform Administration Routes (Phase 1E.3)
Route::middleware(['auth:sanctum', 'active', 'platform.superadmin'])->prefix('platform')->group(function () {
    Route::get('/payment-providers', [PaymentProviderController::class, 'index']);
    Route::get('/payment-providers/{provider}', [PaymentProviderController::class, 'show']);
    Route::post('/payment-providers/{provider}/enable', [PaymentProviderController::class, 'enable']);
    Route::post('/payment-providers/{provider}/disable', [PaymentProviderController::class, 'disable']);
    Route::get('/tenants/{tenant}/payment-connections', [PaymentProviderController::class, 'connections']);
    Route::put('/tenants/{tenant}/payment-connections/{provider}', [PaymentProviderController::class, 'updateConnection']);
    Route::post('/tenants/{tenant}/payment-connections/{provider}/verify', [PaymentProviderController::class, 'verifyConnection']);

    // Platform Subscription Catalog & Tenant Management (Phase 1J)
    Route::get('/subscription-plans', [SubscriptionPlanController::class, 'index']);
    Route::post('/subscription-plans', [SubscriptionPlanController::class, 'store']);
    Route::patch('/subscription-plans/{id}', [SubscriptionPlanController::class, 'update']);
    Route::get('/subscriptions', [SubscriptionPlanController::class, 'subscriptions']);
    Route::post('/tenants/{tenant}/subscription/assign', [SubscriptionPlanController::class, 'assign']);
});

// Tenant Subscription Management Routes (Phase 1J)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('subscription')->group(function () {
    Route::get('/', [SubscriptionController::class, 'show'])->middleware('permission:subscription.view');
    Route::get('/usage', [SubscriptionController::class, 'usage'])->middleware('permission:subscription.view');
    Route::get('/entitlements', [SubscriptionController::class, 'entitlements'])->middleware('permission:subscription.view');
    Route::post('/cancel', [SubscriptionController::class, 'cancel'])->middleware(['permission:subscription.manage', 'idempotent']);
});

// WhatsApp Integration Routes (V1)
Route::middleware(['auth:sanctum', 'active', 'tenant'])->prefix('whatsapp')->group(function () {
    Route::get('/connection', [WhatsAppConnectionController::class, 'show'])->middleware('permission:whatsapp.view');
    Route::match(['post', 'put'], '/connection', [WhatsAppConnectionController::class, 'update'])->middleware(['permission:whatsapp.manage', 'idempotent']);
    Route::post('/connection/verify', [WhatsAppConnectionController::class, 'verify'])->middleware(['permission:whatsapp.manage', 'idempotent']);
    Route::post('/connection/test', [WhatsAppConnectionController::class, 'testSend'])->middleware(['permission:whatsapp.send', 'idempotent']);

    Route::get('/messages', [WhatsAppMessageController::class, 'index'])->middleware('permission:whatsapp.view');
    Route::get('/messages/{id}', [WhatsAppMessageController::class, 'show'])->middleware('permission:whatsapp.view');
    Route::post('/messages/{id}/resend', [WhatsAppMessageController::class, 'resend'])->middleware(['permission:whatsapp.send', 'idempotent']);
    Route::get('/preferences', [WhatsAppConnectionController::class, 'getPreferences'])->middleware('permission:whatsapp.view');
    Route::match(['post', 'put'], '/preferences', [WhatsAppConnectionController::class, 'updatePreferences'])->middleware(['permission:whatsapp.manage', 'idempotent']);
    Route::post('/query', [WhatsAppMessageController::class, 'query'])->middleware(['permission:whatsapp.view', 'idempotent']);
    Route::post('/daily-summary', [WhatsAppMessageController::class, 'sendDailySummary'])->middleware(['permission:whatsapp.send', 'idempotent']);
    Route::post('/order/{orderId}/confirmation', [WhatsAppMessageController::class, 'sendOrderConfirmation'])->middleware(['permission:whatsapp.send', 'idempotent']);
    Route::post('/booking/{bookingId}/confirmation', [WhatsAppMessageController::class, 'sendBookingConfirmation'])->middleware(['permission:whatsapp.send', 'idempotent']);
    Route::post('/booking/{bookingId}/reminder', [WhatsAppMessageController::class, 'sendBookingReminder'])->middleware(['permission:whatsapp.send', 'idempotent']);
    Route::post('/promotions/send', [WhatsAppMessageController::class, 'sendPromotion'])->middleware(['permission:whatsapp.send', 'idempotent']);
    Route::post('/receipt/{saleId}/send', [WhatsAppMessageController::class, 'sendReceipt'])->middleware(['permission:whatsapp.send', 'idempotent']);
});

// Tenant-Scoped Foundational Routes
Route::middleware(['auth:sanctum', 'tenant', 'idempotent'])->prefix('tenant')->group(function () {

    // Active Tenant Context Details
    Route::get('/context', function () {
        $context = app(TenantContext::class);

        return ApiResponse::success(data: [
            'tenant_id' => $context->id(),
            'tenant_name' => $context->tenant()->name,
            'role' => $context->role(),
            'outlet_access' => $context->membership()->outlet_access,
            'assigned_outlet_ids' => $context->membership()->assigned_outlet_ids,
        ]);
    });

    // Test Records (RLS + Tenant Scope Verification)
    Route::get('/test-records', function () {
        $records = TenantTestRecord::all();

        return ApiResponse::success(data: $records);
    });

    Route::post('/test-records', function (Request $request) {
        $validated = $request->validate([
            'name' => 'required|string',
            'secret_data' => 'nullable|string',
        ]);

        $record = TenantTestRecord::create($validated);

        return ApiResponse::success(
            data: $record,
            status: 201
        );
    });

    // Outlet Access Verification
    Route::get('/outlets/{outletId}/verify', function (string $outletId) {
        $outlet = Outlet::findOrFail($outletId);

        return ApiResponse::success(data: [
            'outlet_id' => $outlet->id,
            'name' => $outlet->name,
            'authorized' => true,
        ]);
    })->middleware('outlet:outletId');

    // Role & Permission Verification Routes
    Route::post('/owner-only-action', function () {
        $context = app(TenantContext::class);
        if (! $context->isOwner()) {
            return ApiResponse::error(
                message: 'This action is restricted to tenant owners only.',
                code: 'OWNER_ONLY_ACTION',
                status: 403
            );
        }

        return ApiResponse::success(message: 'Owner action executed.');
    });

    Route::post('/pos-sell-action', function () {
        return ApiResponse::success(message: 'POS sell action executed.');
    })->middleware('permission:pos.sell');

    Route::post('/finance-action', function () {
        return ApiResponse::success(message: 'Finance action executed.');
    })->middleware('permission:finance.view');
});
