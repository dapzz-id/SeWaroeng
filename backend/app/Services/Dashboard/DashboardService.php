<?php

namespace App\Services\Dashboard;

use App\Models\InventoryItemLocation;
use App\Models\Notification;
use App\Models\Order;
use App\Models\Outlet;
use App\Models\Payment;
use App\Models\PaymentRefund;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Shift;
use App\Models\Table;
use App\Services\Finance\FinanceService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class DashboardService
{
    public function __construct(
        protected FinanceService $financeService,
    ) {}

    /**
     * Resolve date range boundaries in business timezone.
     *
     * @return array{0: Carbon, 1: Carbon, 2: string, 3: string}
     */
    public function resolveDateRange(
        string $period,
        ?string $customStartDate,
        ?string $customEndDate,
        string $timezone = 'Asia/Jakarta'
    ): array {
        $now = Carbon::now($timezone);

        switch ($period) {
            case 'yesterday':
                $start = Carbon::yesterday($timezone)->startOfDay();
                $end = Carbon::yesterday($timezone)->endOfDay();
                break;
            case '7_days':
            case 'last_7_days':
                $start = $now->copy()->subDays(6)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case '30_days':
            case 'last_30_days':
                $start = $now->copy()->subDays(29)->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
            case 'custom':
                $start = $customStartDate ? Carbon::parse($customStartDate, $timezone)->startOfDay() : $now->copy()->startOfDay();
                $end = $customEndDate ? Carbon::parse($customEndDate, $timezone)->endOfDay() : $now->copy()->endOfDay();
                break;
            case 'today':
            default:
                $start = $now->copy()->startOfDay();
                $end = $now->copy()->endOfDay();
                break;
        }

        return [$start, $end, $start->toDateString(), $end->toDateString()];
    }

    /**
     * Generate authoritative dashboard read model data.
     *
     * @param  array<string>|null  $authorizedOutletIds
     */
    public function getDashboardData(
        string $tenantId,
        string $period = 'today',
        ?string $outletId = null,
        ?string $customStartDate = null,
        ?string $customEndDate = null,
        ?array $authorizedOutletIds = null,
        bool $canViewFinance = true,
        string $timezone = 'Asia/Jakarta'
    ): array {
        // 1. Authorize outlet access
        if ($outletId && $authorizedOutletIds !== null && ! in_array($outletId, $authorizedOutletIds, true)) {
            throw new AccessDeniedHttpException('Unauthorized to view metrics for this outlet.');
        }

        // 2. Resolve date boundaries
        [$startDate, $endDate, $startDateStr, $endDateStr] = $this->resolveDateRange(
            period: $period,
            customStartDate: $customStartDate,
            customEndDate: $customEndDate,
            timezone: $timezone
        );

        // 3. Sales KPIs (Finalized only)
        $salesQuery = Sale::query()
            ->where('tenant_id', $tenantId)
            ->where('status', Sale::STATUS_FINALIZED)
            ->whereBetween('finalized_at', [$startDate, $endDate]);

        if ($outletId) {
            $salesQuery->where('outlet_id', $outletId);
        } elseif ($authorizedOutletIds !== null) {
            $salesQuery->whereIn('outlet_id', $authorizedOutletIds);
        }

        $salesRows = $salesQuery->get(['id', 'total_minor', 'outlet_id', 'customer_id']);
        $transactionCount = $salesRows->count();
        $grossSalesMinor = (int) $salesRows->sum('total_minor');

        // Authoritative Refunds
        $refundsQuery = PaymentRefund::query()
            ->join('payments', 'payment_refunds.payment_id', '=', 'payments.id')
            ->where('payment_refunds.tenant_id', $tenantId)
            ->where('payment_refunds.status', PaymentRefund::STATUS_COMPLETED)
            ->whereBetween('payment_refunds.completed_at', [$startDate, $endDate]);

        if ($outletId) {
            $refundsQuery->join('sales', 'payments.sale_id', '=', 'sales.id')
                ->where('sales.outlet_id', $outletId);
        } elseif ($authorizedOutletIds !== null) {
            $refundsQuery->join('sales', 'payments.sale_id', '=', 'sales.id')
                ->whereIn('sales.outlet_id', $authorizedOutletIds);
        }

        $refundsMinor = (int) $refundsQuery->sum('payment_refunds.amount_minor');
        $netSalesMinor = max(0, $grossSalesMinor - $refundsMinor);
        $aovMinor = $transactionCount > 0 ? intdiv($netSalesMinor, $transactionCount) : 0;

        // 4. Finance metrics (P&L & Cashflow from authoritative double-entry)
        $grossProfitMinor = null;
        $netCashflowMinor = null;
        $cogsMinor = null;

        if ($canViewFinance) {
            $pnl = $this->financeService->getProfitAndLoss(
                tenantId: $tenantId,
                outletId: $outletId,
                startDate: $startDate,
                endDate: $endDate,
                timezone: $timezone
            );
            $grossProfitMinor = $pnl['gross_profit_minor'];
            $cogsMinor = $pnl['cogs_minor'];

            $cashFlow = $this->financeService->getCashFlow(
                tenantId: $tenantId,
                outletId: $outletId,
                startDate: $startDate,
                endDate: $endDate,
                timezone: $timezone
            );
            $netCashflowMinor = $cashFlow['net_cashflow_minor'];
        }

        // 5. Top Products (Ranked by revenue & units sold)
        $topProductsQuery = SaleItem::query()
            ->join('sales', 'sale_items.sale_id', '=', 'sales.id')
            ->where('sale_items.tenant_id', $tenantId)
            ->where('sales.status', Sale::STATUS_FINALIZED)
            ->whereBetween('sales.finalized_at', [$startDate, $endDate]);

        if ($outletId) {
            $topProductsQuery->where('sales.outlet_id', $outletId);
        } elseif ($authorizedOutletIds !== null) {
            $topProductsQuery->whereIn('sales.outlet_id', $authorizedOutletIds);
        }

        $topProducts = $topProductsQuery
            ->select(
                'sale_items.product_name_snapshot as product_name',
                DB::raw('SUM(sale_items.quantity) as units_sold'),
                DB::raw('SUM(sale_items.subtotal_minor) as revenue_minor')
            )
            ->groupBy('sale_items.product_name_snapshot')
            ->orderByDesc('revenue_minor')
            ->limit(5)
            ->get()
            ->map(fn ($r) => [
                'product_name' => $r->product_name,
                'units_sold' => (float) $r->units_sold,
                'revenue_minor' => (int) $r->revenue_minor,
            ])
            ->values()
            ->all();

        // 6. Low Stock Items
        $lowStockQuery = InventoryItemLocation::query()
            ->join('inventory_items', 'inventory_item_locations.inventory_item_id', '=', 'inventory_items.id')
            ->join('inventory_locations', 'inventory_item_locations.location_id', '=', 'inventory_locations.id')
            ->where('inventory_item_locations.tenant_id', $tenantId)
            ->where('inventory_item_locations.reorder_level', '>', 0)
            ->whereRaw('(inventory_item_locations.on_hand - inventory_item_locations.reserved) <= inventory_item_locations.reorder_level');

        if ($outletId) {
            $lowStockQuery->where('inventory_locations.outlet_id', $outletId);
        } elseif ($authorizedOutletIds !== null) {
            $lowStockQuery->whereIn('inventory_locations.outlet_id', $authorizedOutletIds);
        }

        $lowStockItems = $lowStockQuery
            ->select(
                'inventory_items.id as item_id',
                'inventory_items.name as item_name',
                'inventory_items.sku',
                'inventory_item_locations.on_hand',
                'inventory_item_locations.reserved',
                'inventory_item_locations.reorder_level',
                'inventory_locations.name as location_name',
                'inventory_locations.outlet_id'
            )
            ->limit(10)
            ->get()
            ->map(fn ($i) => [
                'item_id' => $i->item_id,
                'item_name' => $i->item_name,
                'sku' => $i->sku,
                'available' => (float) bcsub((string) $i->on_hand, (string) $i->reserved, 4),
                'reorder_level' => (float) $i->reorder_level,
                'location_name' => $i->location_name,
                'outlet_id' => $i->outlet_id,
            ])
            ->values()
            ->all();

        // 7. Top Customers (Excludes walk-in customers where customer_id is null)
        $topCustomersQuery = Sale::query()
            ->join('customers', 'sales.customer_id', '=', 'customers.id')
            ->where('sales.tenant_id', $tenantId)
            ->where('sales.status', Sale::STATUS_FINALIZED)
            ->whereNotNull('sales.customer_id')
            ->whereBetween('sales.finalized_at', [$startDate, $endDate]);

        if ($outletId) {
            $topCustomersQuery->where('sales.outlet_id', $outletId);
        } elseif ($authorizedOutletIds !== null) {
            $topCustomersQuery->whereIn('sales.outlet_id', $authorizedOutletIds);
        }

        $topCustomers = $topCustomersQuery
            ->select(
                'customers.id as customer_id',
                'customers.name as customer_name',
                DB::raw('COUNT(sales.id) as transaction_count'),
                DB::raw('SUM(sales.total_minor) as total_spend_minor')
            )
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('total_spend_minor')
            ->limit(5)
            ->get()
            ->map(fn ($c) => [
                'customer_id' => $c->customer_id,
                'customer_name' => $c->customer_name,
                'transaction_count' => (int) $c->transaction_count,
                'total_spend_minor' => (int) $c->total_spend_minor,
            ])
            ->values()
            ->all();

        // 8. Payment Status Breakdown
        $paymentsQuery = Payment::query()
            ->where('tenant_id', $tenantId)
            ->whereBetween('created_at', [$startDate, $endDate]);

        if ($outletId) {
            $paymentsQuery->whereHas('sale', fn ($q) => $q->where('outlet_id', $outletId));
        } elseif ($authorizedOutletIds !== null) {
            $paymentsQuery->whereHas('sale', fn ($q) => $q->whereIn('outlet_id', $authorizedOutletIds));
        }

        $paymentStatuses = $paymentsQuery
            ->select('status', DB::raw('COUNT(*) as count'), DB::raw('SUM(amount_minor) as total_minor'))
            ->groupBy('status')
            ->get()
            ->mapWithKeys(fn ($p) => [
                $p->status => [
                    'count' => (int) $p->count,
                    'total_minor' => (int) $p->total_minor,
                ],
            ])
            ->all();

        // 9. Outlet Performance Overview (For multi-outlet owners/managers)
        $outletOverview = [];
        if (! $outletId && ($authorizedOutletIds === null || count($authorizedOutletIds) > 1)) {
            $outletSalesQuery = Sale::query()
                ->join('outlets', 'sales.outlet_id', '=', 'outlets.id')
                ->where('sales.tenant_id', $tenantId)
                ->where('sales.status', Sale::STATUS_FINALIZED)
                ->whereBetween('sales.finalized_at', [$startDate, $endDate]);

            if ($authorizedOutletIds !== null) {
                $outletSalesQuery->whereIn('sales.outlet_id', $authorizedOutletIds);
            }

            $outletOverview = $outletSalesQuery
                ->select(
                    'outlets.id as outlet_id',
                    'outlets.name as outlet_name',
                    DB::raw('COUNT(sales.id) as transaction_count'),
                    DB::raw('SUM(sales.total_minor) as gross_sales_minor')
                )
                ->groupBy('outlets.id', 'outlets.name')
                ->orderByDesc('gross_sales_minor')
                ->get()
                ->map(fn ($o) => [
                    'outlet_id' => $o->outlet_id,
                    'outlet_name' => $o->outlet_name,
                    'transaction_count' => (int) $o->transaction_count,
                    'gross_sales_minor' => (int) $o->gross_sales_minor,
                    'aov_minor' => $o->transaction_count > 0 ? intdiv((int) $o->gross_sales_minor, (int) $o->transaction_count) : 0,
                ])
                ->values()
                ->all();
        }

        // 10. Operational Shift Summary (If single outlet selected)
        $shiftSummary = null;
        if ($outletId) {
            $latestShift = Shift::where('tenant_id', $tenantId)
                ->where('outlet_id', $outletId)
                ->latest()
                ->first();

            if ($latestShift) {
                $shiftSummary = [
                    'shift_id' => $latestShift->id,
                    'status' => $latestShift->status,
                    'opening_cash' => (int) $latestShift->opening_cash_minor,
                    'expected_closing_cash' => (int) $latestShift->expected_cash_minor,
                    'actual_closing_cash' => (int) ($latestShift->closing_cash_minor ?? 0),
                    'variance' => $latestShift->isClosed() ? (int) $latestShift->variance_minor : 0,
                ];
            }
        }

        // 11. Alerts Summary
        $unreadAlertsCount = Notification::where('tenant_id', $tenantId)
            ->whereNull('read_at')
            ->count();

        // 12. Table & Order Operations (V1 Operational Read Model)
        $tablesQuery = Table::query()->where('tenant_id', $tenantId);
        if ($outletId) {
            $tablesQuery->where('outlet_id', $outletId);
        } elseif ($authorizedOutletIds !== null) {
            $tablesQuery->whereIn('outlet_id', $authorizedOutletIds);
        }

        $occupiedTables = (clone $tablesQuery)->where('status', Table::STATUS_OCCUPIED)->count();
        $reservedTables = (clone $tablesQuery)->where('status', Table::STATUS_RESERVED)->count();
        $availableTables = (clone $tablesQuery)->where('status', Table::STATUS_AVAILABLE)->count();

        $activeOrdersQuery = Order::query()->where('tenant_id', $tenantId)
            ->whereIn('status', [Order::STATUS_SUBMITTED, Order::STATUS_ACCEPTED, Order::STATUS_PREPARING, Order::STATUS_READY]);
        if ($outletId) {
            $activeOrdersQuery->where('outlet_id', $outletId);
        } elseif ($authorizedOutletIds !== null) {
            $activeOrdersQuery->whereIn('outlet_id', $authorizedOutletIds);
        }
        $activeOrders = $activeOrdersQuery->count();

        return [
            'period' => [
                'name' => $period,
                'start_date' => $startDateStr,
                'end_date' => $endDateStr,
                'timezone' => $timezone,
            ],
            'kpi' => [
                'gross_sales_minor' => $grossSalesMinor,
                'refunds_minor' => $refundsMinor,
                'net_sales_minor' => $netSalesMinor,
                'transaction_count' => $transactionCount,
                'average_order_value_minor' => $aovMinor,
                'gross_profit_minor' => $grossProfitMinor,
                'cogs_minor' => $cogsMinor,
                'net_cashflow_minor' => $netCashflowMinor,
            ],
            'table_operations' => [
                'occupied_tables' => $occupiedTables,
                'reserved_tables' => $reservedTables,
                'available_tables' => $availableTables,
                'active_orders' => $activeOrders,
            ],
            'top_products' => $topProducts,
            'low_stock' => $lowStockItems,
            'top_customers' => $topCustomers,
            'payment_summary' => (object) $paymentStatuses,
            'outlet_summary' => $outletOverview,
            'shift_summary' => $shiftSummary,
            'alerts' => [
                'unread_count' => $unreadAlertsCount,
                'has_critical' => Notification::where('tenant_id', $tenantId)
                    ->whereNull('read_at')
                    ->where('severity', Notification::SEVERITY_CRITICAL)
                    ->exists(),
            ],
        ];
    }
}
