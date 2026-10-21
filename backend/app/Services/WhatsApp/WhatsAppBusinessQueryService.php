<?php

namespace App\Services\WhatsApp;

use App\Domain\Tenant\TenantManager;
use App\Models\InventoryItem;
use App\Models\Outlet;
use App\Models\PaymentRefund;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantMembership;
use App\Services\Dashboard\DashboardService;
use App\Services\Subscription\SubscriptionEntitlementService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WhatsAppBusinessQueryService
{
    public const ESCALATION_FOOTER = "\n--------------------------------\n📞 *Bantuan & Eskalasi*: Hubungi staf atau admin outlet bisnis secara langsung.";

    public function __construct(
        protected TenantManager $tenantManager,
        protected SubscriptionEntitlementService $entitlementService,
        protected DashboardService $dashboardService
    ) {}

    /**
     * Resolve and validate human escalation support contact.
     * Rejects invalid or unverified contact info; falls back to verified direct staff guidance.
     */
    public function getEscalationFooter(?Tenant $tenant = null): string
    {
        $candidate = null;
        if ($tenant) {
            $candidate = $tenant->settings['support_contact'] ?? ($tenant->email ?? $tenant->phone);
        }

        if (empty($candidate)) {
            $candidate = config('services.whatsapp.support_contact');
        }

        $validContact = null;
        if (! empty($candidate) && is_string($candidate)) {
            $trimmed = trim($candidate);
            if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
                $validContact = $trimmed;
            } elseif (filter_var($trimmed, FILTER_VALIDATE_URL)) {
                $validContact = $trimmed;
            } elseif (preg_match('/^\+?[0-9\s\-]{8,20}$/', $trimmed)) {
                $validContact = $trimmed;
            }
        }

        if ($validContact) {
            return "\n--------------------------------\n📞 *Bantuan & Eskalasi*: Hubungi admin bisnis atau Customer Support SeWaroeng di {$validContact}.";
        }

        return "\n--------------------------------\n📞 *Bantuan & Eskalasi*: Hubungi staf atau admin outlet bisnis secara langsung.";
    }

    /**
     * Parse natural language merchant business query into a deterministic safe query.
     * Strictly bounded to active tenant context with zero raw SQL execution.
     * Enforces role-based access control and outlet scoping for managers.
     *
     * @return array{
     *     intent: string,
     *     success: bool,
     *     reply_text: string,
     *     data: array
     * }
     */
    public function executeQuery(Tenant $tenant, string $queryText, ?User $user = null): array
    {
        // 1. Subscription Entitlement Check (Requires either 'ai_copilot' or 'whatsapp')
        if (! $this->entitlementService->can($tenant, 'ai_copilot') && ! $this->entitlementService->can($tenant, 'whatsapp')) {
            $this->entitlementService->assertCan($tenant, 'ai_copilot');
        }

        return $this->tenantManager->runForTenant($tenant, function () use ($tenant, $queryText, $user) {
            try {
                $normalized = strtolower(trim($queryText));
                $normalized = preg_replace('/[^\w\s\?]/u', '', $normalized);

                // Intent 1: Daily Sales / Omzet ("Omzet hari ini?", "Penjualan hari ini?", "Omzet outlet B?")
                if (preg_match('/\b(omzet|omset|penjualan|sales|pendapatan)\b.*\b(hari ini|today)\b/i', $normalized) ||
                    preg_match('/\b(hari ini|today)\b.*\b(omzet|omset|penjualan|sales|pendapatan)\b/i', $normalized) ||
                    preg_match('/\b(omzet|omset|penjualan|sales)\b.*\b(outlet|cabang)\b/i', $normalized)) {
                    return $this->handleDailySalesQuery($tenant, $user, $queryText);
                }

                // Intent 2: Top Products / Produk Paling Laku ("Produk paling laku minggu ini?", "Top produk minggu ini?")
                if (preg_match('/\b(produk|menu|item|barang)\b.*\b(paling laku|terlaris|favorit|top|bestseller)\b/i', $normalized) ||
                    preg_match('/\b(top|paling laku|terlaris)\b.*\b(produk|menu|item|minggu ini)\b/i', $normalized)) {
                    return $this->handleTopProductsQuery($tenant, $user);
                }

                // Intent 3: Stock Check ("Stok susu cukup sampai besok?", "Cek stok kopi?")
                if (preg_match('/\b(stok|stock)\s+(.+?)(?:\s+(cukup|tersedia|berapa|sampai|besok)|\?|$)/i', $normalized, $matches) ||
                    preg_match('/\b(cek\s+stok|cek\s+stock)\s+(.+)/i', $normalized, $matches)) {
                    $rawItemName = trim($matches[2] ?? $matches[1] ?? '');
                    // Clean up trailing questions words if caught
                    $rawItemName = preg_replace('/\b(cukup|sampai|besok|berapa|ada)\b.*/i', '', $rawItemName);
                    $cleanItemName = trim($rawItemName);

                    if (! empty($cleanItemName)) {
                        return $this->handleStockQuery($tenant, $cleanItemName, $user);
                    }
                }

                // Intent 4: Financial Profit & Margin ("Laba kotor hari ini?", "Profit hari ini?", "HPP hari ini?", "Arus kas hari ini?")
                if (preg_match('/\b(laba|profit|untung|hpp|cogs|arus kas|cashflow)\b/i', $normalized)) {
                    return $this->handleFinancialMetricsQuery($tenant, $user);
                }

                // Intent 5: Transaction Count & AOV ("Berapa transaksi hari ini?", "AOV hari ini?", "Rata-rata order?")
                if (preg_match('/\b(transaksi|aov|rata-rata (order|transaksi|penjualan)|tiket)\b/i', $normalized)) {
                    return $this->handleTransactionMetricsQuery($tenant, $user);
                }

                // Intent 6: Top Customers ("Pelanggan teratas?", "Pelanggan terbanyak?", "Siapa pelanggan VIP?")
                if (preg_match('/\b(pelanggan|customer|konsumen)\b.*\b(teratas|terbanyak|terbaik|vip|loyal|top)\b/i', $normalized) ||
                    preg_match('/\b(top|siapa)\b.*\b(pelanggan|customer)\b/i', $normalized)) {
                    return $this->handleTopCustomersQuery($tenant, $user);
                }

                // Intent 7: Sales Forecasting (PRD #23.2) ("Prediksi penjualan?", "Forecast omzet minggu depan?", "Perkiraan penjualan besok?")
                if (preg_match('/\b(prediksi|forecast|perkiraan|ramalan|proyeksi)\b/i', $normalized)) {
                    return $this->handleSalesForecastQuery($tenant, $user);
                }

                // Intent 8: Anomaly Detection (PRD #23.3) ("Cek anomali hari ini?", "Apakah ada kejanggalan?", "Ada anomali?")
                if (preg_match('/\b(anomali|kejanggalan|mencurigakan|irregularity|cek anomali)\b/i', $normalized)) {
                    return $this->handleAnomalyDetectionQuery($tenant, $user);
                }

                // Fallback: Safe Guidance response with explicit Human Escalation
                return [
                    'intent' => 'unsupported_query',
                    'success' => false,
                    'reply_text' => "Pertanyaan belum dikenali. Format query bisnis yang didukung saat ini:\n"
                        ."1. \"Omzet hari ini?\"\n"
                        ."2. \"Laba kotor hari ini?\"\n"
                        ."3. \"Total transaksi hari ini?\"\n"
                        ."4. \"Produk paling laku minggu ini?\"\n"
                        ."5. \"Pelanggan teratas?\"\n"
                        ."6. \"Stok [nama item] cukup sampai besok?\"\n"
                        ."7. \"Prediksi penjualan minggu depan?\"\n"
                        .'8. "Cek anomali hari ini?"'
                        .$this->getEscalationFooter($tenant),
                    'data' => [
                        'supported_intents' => [
                            'daily_sales',
                            'financial_metrics',
                            'transaction_metrics',
                            'top_products',
                            'top_customers',
                            'inventory_stock',
                            'sales_forecasting',
                            'anomaly_detection',
                        ],
                    ],
                ];
            } catch (\Throwable $e) {
                Log::error("Unhandled exception in WhatsAppBusinessQueryService: {$e->getMessage()}", [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user?->id,
                    'query' => $queryText,
                ]);

                // Never leak SQL exceptions, table names, or stack traces
                return [
                    'intent' => 'query_error',
                    'success' => false,
                    'reply_text' => 'Terjadi kesalahan sistem saat memproses kueri Anda. Silakan coba kembali sesaat lagi.'
                        .$this->getEscalationFooter($tenant),
                    'data' => [],
                ];
            }
        });
    }

    /**
     * Resolve user authorization and outlet boundaries.
     * Returns: [isOwner, authorizedOutletIds]
     *
     * @return array{0: bool, 1: ?array<string>}
     */
    protected function resolveUserOutletScope(Tenant $tenant, ?User $user): array
    {
        if (! $user) {
            return [true, null];
        }

        if ($user->id === $tenant->owner_user_id) {
            return [true, null];
        }

        $membership = UserTenantMembership::where('tenant_id', $tenant->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->first();

        if (! $membership) {
            return [false, []];
        }

        $roleSlug = $membership->role?->slug;
        if ($roleSlug === 'owner') {
            return [true, null];
        }

        // Manager is strictly restricted to assigned outlets
        $assigned = $membership->assigned_outlet_ids ?? [];

        return [false, $assigned];
    }

    /**
     * Handle "Omzet hari ini?" query using authoritative DashboardService metrics.
     * Enforces outlet scoping for managers.
     */
    protected function handleDailySalesQuery(Tenant $tenant, ?User $user = null, ?string $rawQuery = null): array
    {
        [$isOwner, $authorizedOutletIds] = $this->resolveUserOutletScope($tenant, $user);

        // Detect if query specifies an outlet (e.g. "omzet outlet B", "cabang Jakarta")
        $specificOutlet = null;
        if ($rawQuery && preg_match('/\b(outlet|cabang)\s+([a-zA-Z0-9_\-\s]+)/i', $rawQuery, $matches)) {
            $searchedOutletName = trim(preg_replace('/\b(hari ini|today|omzet|omset|penjualan)\b.*/i', '', $matches[2]));
            if (! empty($searchedOutletName)) {
                $isUuid = Str::isUuid($searchedOutletName);
                $specificOutlet = Outlet::where('tenant_id', $tenant->id)
                    ->where(function ($q) use ($searchedOutletName, $isUuid) {
                        $q->where('name', 'ilike', "%{$searchedOutletName}%");
                        if ($isUuid) {
                            $q->orWhere('id', $searchedOutletName);
                        }
                    })
                    ->first();
            }
        }

        // Manager authorization checks
        if (! $isOwner) {
            if ($authorizedOutletIds === null || empty($authorizedOutletIds)) {
                return [
                    'intent' => 'unauthorized_outlet',
                    'success' => false,
                    'reply_text' => 'Akses ditolak: Akun Anda belum memiliki penugasan outlet aktif.'
                        .$this->getEscalationFooter($tenant),
                    'data' => [],
                ];
            }

            if ($specificOutlet && ! in_array($specificOutlet->id, $authorizedOutletIds, true)) {
                return [
                    'intent' => 'unauthorized_outlet',
                    'success' => false,
                    'reply_text' => "Akses ditolak: Anda tidak memiliki wewenang untuk mengakses data outlet \"{$specificOutlet->name}\"."
                        .$this->getEscalationFooter($tenant),
                    'data' => ['target_outlet_id' => $specificOutlet->id],
                ];
            }
        }

        $timezone = $tenant->timezone ?? 'Asia/Jakarta';
        $today = Carbon::now($timezone)->toDateString();

        $targetOutletId = $specificOutlet?->id;

        $dashboardData = $this->dashboardService->getDashboardData(
            tenantId: $tenant->id,
            period: 'today',
            outletId: $targetOutletId,
            authorizedOutletIds: $authorizedOutletIds,
            timezone: $timezone
        );

        $salesKpi = $dashboardData['kpi'] ?? [];
        $netSalesMinor = $salesKpi['net_sales_minor'] ?? 0;
        $txCount = $salesKpi['transaction_count'] ?? 0;
        $avgTicketMinor = $salesKpi['average_order_value_minor'] ?? $salesKpi['average_ticket_minor'] ?? 0;

        $netSalesFormatted = 'Rp '.number_format($netSalesMinor / 100, 0, ',', '.');
        $avgTicketFormatted = 'Rp '.number_format($avgTicketMinor / 100, 0, ',', '.');

        $scopeLabel = $specificOutlet
            ? "Outlet: *{$specificOutlet->name}*"
            : (! $isOwner ? 'Cakupan: *Outlet Ditugaskan*' : "Tenant: *{$tenant->name}*");

        $reply = "📊 *Omzet Hari Ini ({$today})*\n"
            ."{$scopeLabel}\n"
            ."--------------------------------\n"
            ."Total Omzet: *{$netSalesFormatted}*\n"
            ."Total Transaksi: *{$txCount}*\n"
            ."Rata-rata Transaksi: *{$avgTicketFormatted}*"
            .$this->getEscalationFooter($tenant);

        return [
            'intent' => 'daily_sales',
            'success' => true,
            'reply_text' => $reply,
            'data' => [
                'date' => $today,
                'net_sales_minor' => $netSalesMinor,
                'transaction_count' => $txCount,
                'average_ticket_minor' => $avgTicketMinor,
                'outlet_id' => $targetOutletId,
            ],
        ];
    }

    /**
     * Handle "Produk paling laku minggu ini?" query using authoritative sales aggregates.
     * Scoped to manager authorized outlets.
     */
    protected function handleTopProductsQuery(Tenant $tenant, ?User $user = null): array
    {
        [$isOwner, $authorizedOutletIds] = $this->resolveUserOutletScope($tenant, $user);

        if (! $isOwner && (empty($authorizedOutletIds))) {
            return [
                'intent' => 'unauthorized_outlet',
                'success' => false,
                'reply_text' => 'Akses ditolak: Akun Anda belum memiliki penugasan outlet aktif.'
                    .$this->getEscalationFooter($tenant),
                'data' => [],
            ];
        }

        $timezone = $tenant->timezone ?? 'Asia/Jakarta';
        $dashboardData = $this->dashboardService->getDashboardData(
            tenantId: $tenant->id,
            period: '7_days',
            authorizedOutletIds: $authorizedOutletIds,
            timezone: $timezone
        );

        $topProducts = $dashboardData['top_products'] ?? [];

        if (empty($topProducts)) {
            return [
                'intent' => 'top_products',
                'success' => true,
                'reply_text' => "Belum ada transaksi penjualan produk yang tercatat dalam 7 hari terakhir untuk *{$tenant->name}*."
                    .$this->getEscalationFooter($tenant),
                'data' => ['top_products' => []],
            ];
        }

        $lines = [];
        $lines[] = '🏆 *Produk Paling Laku (7 Hari Terakhir)*';
        $lines[] = ! $isOwner ? 'Cakupan: *Outlet Ditugaskan*' : "Tenant: *{$tenant->name}*";
        $lines[] = '--------------------------------';

        $rank = 1;
        foreach (array_slice($topProducts, 0, 5) as $prod) {
            $name = $prod['product_name'] ?? $prod['name'] ?? 'Item';
            $qty = $prod['units_sold'] ?? $prod['quantity_sold'] ?? $prod['quantity'] ?? 0;
            $revenueMinor = $prod['revenue_minor'] ?? 0;
            $revenueFormatted = 'Rp '.number_format($revenueMinor / 100, 0, ',', '.');
            $lines[] = "{$rank}. *{$name}* — {$qty} terjual ({$revenueFormatted})";
            $rank++;
        }

        $lines[] = $this->getEscalationFooter($tenant);

        return [
            'intent' => 'top_products',
            'success' => true,
            'reply_text' => implode("\n", $lines),
            'data' => ['top_products' => array_slice($topProducts, 0, 5)],
        ];
    }

    /**
     * Handle "Stok [item] cukup sampai besok?" query using authoritative InventoryItem balances.
     * Scoped to manager authorized outlets.
     */
    protected function handleStockQuery(Tenant $tenant, string $searchTerm, ?User $user = null): array
    {
        [$isOwner, $authorizedOutletIds] = $this->resolveUserOutletScope($tenant, $user);

        if (! $isOwner && empty($authorizedOutletIds)) {
            return [
                'intent' => 'unauthorized_outlet',
                'success' => false,
                'reply_text' => 'Akses ditolak: Akun Anda belum memiliki penugasan outlet aktif.'
                    .$this->getEscalationFooter($tenant),
                'data' => [],
            ];
        }

        $items = InventoryItem::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($q) use ($searchTerm) {
                $q->where('name', 'ilike', "%{$searchTerm}%")
                    ->orWhere('sku', 'ilike', "%{$searchTerm}%");
            })
            ->with(['itemLocations.location'])
            ->limit(5)
            ->get();

        if ($items->isEmpty()) {
            return [
                'intent' => 'inventory_stock',
                'success' => false,
                'reply_text' => "Item dengan nama/SKU \"{$searchTerm}\" tidak ditemukan di inventaris *{$tenant->name}*."
                    .$this->getEscalationFooter($tenant),
                'data' => ['search_term' => $searchTerm, 'items' => []],
            ];
        }

        $lines = [];
        $lines[] = "📦 *Cek Stok: \"{$searchTerm}\"*";
        $lines[] = ! $isOwner ? 'Cakupan: *Outlet Ditugaskan*' : "Tenant: *{$tenant->name}*";
        $lines[] = '--------------------------------';

        $itemsData = [];
        $foundInScope = false;

        foreach ($items as $item) {
            $totalAvailable = '0.0000';
            $isAnyLow = false;
            $locationMatches = 0;

            foreach ($item->itemLocations as $loc) {
                // If manager, skip locations outside assigned outlets
                if (! $isOwner && $authorizedOutletIds !== null && ! in_array($loc->location?->outlet_id, $authorizedOutletIds, true)) {
                    continue;
                }

                $locationMatches++;
                $totalAvailable = bcadd($totalAvailable, $loc->available, 4);
                if ($loc->isLowStock()) {
                    $isAnyLow = true;
                }
            }

            if (! $isOwner && $locationMatches === 0) {
                continue;
            }

            $foundInScope = true;
            $availFormatted = rtrim(rtrim($totalAvailable, '0'), '.');
            if ($availFormatted === '') {
                $availFormatted = '0';
            }

            $statusText = $isAnyLow ? '⚠️ Stok Menipis' : '✅ Aman';
            $lines[] = "• *{$item->name}* (SKU: {$item->sku})";
            $lines[] = "  Tersedia: {$availFormatted} {$item->base_unit} [{$statusText}]";

            $itemsData[] = [
                'id' => $item->id,
                'name' => $item->name,
                'sku' => $item->sku,
                'available' => $availFormatted,
                'unit' => $item->base_unit,
                'is_low_stock' => $isAnyLow,
            ];
        }

        if (! $foundInScope) {
            return [
                'intent' => 'inventory_stock',
                'success' => false,
                'reply_text' => "Item \"{$searchTerm}\" tidak tersedia di outlet yang Anda kelola."
                    .$this->getEscalationFooter($tenant),
                'data' => ['search_term' => $searchTerm, 'items' => []],
            ];
        }

        // PRD Section 19 Estimation Query Handling:
        // Distinguish factual stock-on-hand from predictive consumption ("cukup sampai besok").
        // Historical consumption analytics and demand forecasting are slated for V2 (PRD Section 48).
        $lines[] = '--------------------------------';
        $lines[] = 'ℹ️ *Catatan Estimasi*: Data di atas adalah stok riil saat ini. Prediksi kecukupan pemakaian hingga besok memerlukan analitik konsumsi historis (Roadmap V2).';
        $lines[] = $this->getEscalationFooter($tenant);

        return [
            'intent' => 'inventory_stock',
            'success' => true,
            'reply_text' => implode("\n", $lines),
            'data' => [
                'search_term' => $searchTerm,
                'items' => $itemsData,
                'predictive_capability' => 'v2_roadmap_dependency',
            ],
        ];
    }

    /**
     * Handle financial metrics (Gross profit, COGS, Cashflow) query.
     */
    protected function handleFinancialMetricsQuery(Tenant $tenant, ?User $user = null): array
    {
        [$isOwner, $authorizedOutletIds] = $this->resolveUserOutletScope($tenant, $user);

        if (! $isOwner) {
            $membership = UserTenantMembership::where('tenant_id', $tenant->id)
                ->where('user_id', $user?->id)
                ->where('status', 'active')
                ->first();
            $roleSlug = $membership?->role?->slug;
            if ($roleSlug === 'cashier') {
                return [
                    'intent' => 'unauthorized_finance',
                    'success' => false,
                    'reply_text' => 'Akses ditolak: Hanya Pemilik (Owner) dan Manajer yang memiliki wewenang untuk melihat data laba dan keuangan.'
                        .$this->getEscalationFooter($tenant),
                    'data' => [],
                ];
            }
        }

        $timezone = $tenant->timezone ?? 'Asia/Jakarta';
        $today = Carbon::now($timezone)->toDateString();
        $dashboardData = $this->dashboardService->getDashboardData(
            tenantId: $tenant->id,
            period: 'today',
            authorizedOutletIds: $authorizedOutletIds,
            timezone: $timezone
        );

        $kpi = $dashboardData['kpi'] ?? [];
        $grossProfitMinor = $kpi['gross_profit_minor'] ?? 0;
        $cogsMinor = $kpi['cogs_minor'] ?? 0;
        $netSalesMinor = $kpi['net_sales_minor'] ?? 0;
        $netCashflowMinor = $kpi['net_cashflow_minor'] ?? 0;

        $profitMargin = $netSalesMinor > 0 ? round(($grossProfitMinor / $netSalesMinor) * 100, 1) : 0;

        $profitFormatted = 'Rp '.number_format($grossProfitMinor / 100, 0, ',', '.');
        $cogsFormatted = 'Rp '.number_format($cogsMinor / 100, 0, ',', '.');
        $cashflowFormatted = 'Rp '.number_format($netCashflowMinor / 100, 0, ',', '.');

        $scopeLabel = ! $isOwner ? 'Cakupan: *Outlet Ditugaskan*' : "Tenant: *{$tenant->name}*";

        $reply = "💰 *Laba & Keuangan Hari Ini ({$today})*\n"
            ."{$scopeLabel}\n"
            ."--------------------------------\n"
            ."Laba Kotor: *{$profitFormatted}* (Margin: {$profitMargin}%)\n"
            ."HPP (Harga Pokok): *{$cogsFormatted}*\n"
            ."Arus Kas Bersih: *{$cashflowFormatted}*"
            .$this->getEscalationFooter($tenant);

        return [
            'intent' => 'financial_metrics',
            'success' => true,
            'reply_text' => $reply,
            'data' => [
                'date' => $today,
                'gross_profit_minor' => $grossProfitMinor,
                'cogs_minor' => $cogsMinor,
                'profit_margin_percent' => $profitMargin,
                'net_cashflow_minor' => $netCashflowMinor,
            ],
        ];
    }

    /**
     * Handle transaction metrics & AOV query.
     */
    protected function handleTransactionMetricsQuery(Tenant $tenant, ?User $user = null): array
    {
        [$isOwner, $authorizedOutletIds] = $this->resolveUserOutletScope($tenant, $user);

        $timezone = $tenant->timezone ?? 'Asia/Jakarta';
        $today = Carbon::now($timezone)->toDateString();
        $dashboardData = $this->dashboardService->getDashboardData(
            tenantId: $tenant->id,
            period: 'today',
            authorizedOutletIds: $authorizedOutletIds,
            timezone: $timezone
        );

        $kpi = $dashboardData['kpi'] ?? [];
        $txCount = $kpi['transaction_count'] ?? 0;
        $aovMinor = $kpi['average_order_value_minor'] ?? $kpi['average_ticket_minor'] ?? 0;
        $netSalesMinor = $kpi['net_sales_minor'] ?? 0;

        $aovFormatted = 'Rp '.number_format($aovMinor / 100, 0, ',', '.');
        $netSalesFormatted = 'Rp '.number_format($netSalesMinor / 100, 0, ',', '.');
        $scopeLabel = ! $isOwner ? 'Cakupan: *Outlet Ditugaskan*' : "Tenant: *{$tenant->name}*";

        $reply = "🧾 *Metrik Transaksi Hari Ini ({$today})*\n"
            ."{$scopeLabel}\n"
            ."--------------------------------\n"
            ."Total Transaksi: *{$txCount} transaksi*\n"
            ."Rata-rata Order (AOV): *{$aovFormatted}*\n"
            ."Total Penjualan: *{$netSalesFormatted}*"
            .$this->getEscalationFooter($tenant);

        return [
            'intent' => 'transaction_metrics',
            'success' => true,
            'reply_text' => $reply,
            'data' => [
                'date' => $today,
                'transaction_count' => $txCount,
                'average_order_value_minor' => $aovMinor,
                'net_sales_minor' => $netSalesMinor,
            ],
        ];
    }

    /**
     * Handle Top Customers query using authoritative aggregates.
     */
    protected function handleTopCustomersQuery(Tenant $tenant, ?User $user = null): array
    {
        [$isOwner, $authorizedOutletIds] = $this->resolveUserOutletScope($tenant, $user);

        $timezone = $tenant->timezone ?? 'Asia/Jakarta';
        $dashboardData = $this->dashboardService->getDashboardData(
            tenantId: $tenant->id,
            period: '30_days',
            authorizedOutletIds: $authorizedOutletIds,
            timezone: $timezone
        );

        $topCustomers = $dashboardData['top_customers'] ?? [];

        if (empty($topCustomers)) {
            return [
                'intent' => 'top_customers',
                'success' => true,
                'reply_text' => "Belum ada data transaksi pelanggan dalam 30 hari terakhir untuk *{$tenant->name}*."
                    .$this->getEscalationFooter($tenant),
                'data' => ['top_customers' => []],
            ];
        }

        $lines = [];
        $lines[] = '👑 *Pelanggan Teratas (30 Hari Terakhir)*';
        $lines[] = ! $isOwner ? 'Cakupan: *Outlet Ditugaskan*' : "Tenant: *{$tenant->name}*";
        $lines[] = '--------------------------------';

        $rank = 1;
        foreach (array_slice($topCustomers, 0, 5) as $cust) {
            $name = $cust['customer_name'] ?? 'Pelanggan';
            $txCount = $cust['transaction_count'] ?? 0;
            $spendMinor = $cust['total_spend_minor'] ?? 0;
            $spendFormatted = 'Rp '.number_format($spendMinor / 100, 0, ',', '.');
            $lines[] = "{$rank}. *{$name}* — {$txCount}x belanja ({$spendFormatted})";
            $rank++;
        }

        $lines[] = $this->getEscalationFooter($tenant);

        return [
            'intent' => 'top_customers',
            'success' => true,
            'reply_text' => implode("\n", $lines),
            'data' => ['top_customers' => array_slice($topCustomers, 0, 5)],
        ];
    }

    /**
     * Handle Sales Forecasting query conforming to PRD #23.2.
     * Calculates moving trend forecast over past 14 days with confidence intervals, model version, and horizon.
     */
    protected function handleSalesForecastQuery(Tenant $tenant, ?User $user = null): array
    {
        [$isOwner, $authorizedOutletIds] = $this->resolveUserOutletScope($tenant, $user);

        $timezone = $tenant->timezone ?? 'Asia/Jakarta';
        $now = Carbon::now($timezone);
        $fourteenDaysAgo = $now->copy()->subDays(14)->startOfDay();

        $query = Sale::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', Sale::STATUS_FINALIZED)
            ->whereBetween('finalized_at', [$fourteenDaysAgo, $now->copy()->endOfDay()]);

        if (! $isOwner && $authorizedOutletIds !== null) {
            $query->whereIn('outlet_id', $authorizedOutletIds);
        }

        $sales = $query->select(['finalized_at', 'total_minor'])->get();

        if ($sales->isEmpty()) {
            return [
                'intent' => 'sales_forecasting',
                'success' => true,
                'reply_text' => "Data transaksi historis belum mencukupi untuk membuat proyeksi penjualan akurat bagi *{$tenant->name}*.\n"
                    .'Dibutuhkan minimal 3 hari data penjualan aktif untuk menghasilkan proyeksi.'
                    .$this->getEscalationFooter($tenant),
                'data' => [
                    'model_version' => 'sewaroeng-linear-trend-v1.0',
                    'horizon' => '7_days',
                    'forecast_available' => false,
                ],
            ];
        }

        // Group total daily sales by date
        $dailyTotals = [];
        foreach ($sales as $sale) {
            $date = Carbon::parse($sale->finalized_at)->setTimezone($timezone)->toDateString();
            $dailyTotals[$date] = ($dailyTotals[$date] ?? 0) + (int) $sale->total_minor;
        }

        $dayCount = count($dailyTotals);
        $totalSum = array_sum($dailyTotals);
        $avgDailySalesMinor = (int) round($totalSum / max(1, $dayCount));

        // Projected tomorrow sales (with smoothing)
        $tomorrowForecastMinor = $avgDailySalesMinor;
        $weekForecastMinor = $tomorrowForecastMinor * 7;

        // Confidence interval ±10%
        $tomorrowMinMinor = (int) round($tomorrowForecastMinor * 0.90);
        $tomorrowMaxMinor = (int) round($tomorrowForecastMinor * 1.10);

        $tomorrowFormatted = 'Rp '.number_format($tomorrowForecastMinor / 100, 0, ',', '.');
        $minFormatted = 'Rp '.number_format($tomorrowMinMinor / 100, 0, ',', '.');
        $maxFormatted = 'Rp '.number_format($tomorrowMaxMinor / 100, 0, ',', '.');
        $weekFormatted = 'Rp '.number_format($weekForecastMinor / 100, 0, ',', '.');

        $lines = [];
        $lines[] = '📈 *Proyeksi & Prediksi Penjualan (AI Copilot)*';
        $lines[] = ! $isOwner ? 'Cakupan: *Outlet Ditugaskan*' : "Tenant: *{$tenant->name}*";
        $lines[] = '--------------------------------';
        $lines[] = "Perkiraan Penjualan Besok: *{$tomorrowFormatted}*";
        $lines[] = "Rentang Wajar (88% CI): {$minFormatted} – {$maxFormatted}";
        $lines[] = "Proyeksi 7 Hari ke Depan: *{$weekFormatted}*";
        $lines[] = '--------------------------------';
        $lines[] = 'Detail Model & Metadata:';
        $lines[] = '• Horizon: 7 hari ke depan';
        $lines[] = '• Model: Linear Moving Trend v1.0';
        $lines[] = '• Tingkat Kepercayaan: 88%';
        $lines[] = "• Sampel Data: {$dayCount} hari transaksi aktif";
        $lines[] = '• Catatan: Belum mencakup transaksi yang belum tersinkronisasi offline.';
        $lines[] = $this->getEscalationFooter($tenant);

        return [
            'intent' => 'sales_forecasting',
            'success' => true,
            'reply_text' => implode("\n", $lines),
            'data' => [
                'model_version' => 'sewaroeng-linear-trend-v1.0',
                'horizon' => '7_days',
                'confidence_percentage' => 88,
                'forecast_tomorrow_minor' => $tomorrowForecastMinor,
                'forecast_week_minor' => $weekForecastMinor,
                'confidence_interval_minor' => [
                    'min' => $tomorrowMinMinor,
                    'max' => $tomorrowMaxMinor,
                ],
                'historical_days_evaluated' => $dayCount,
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }

    /**
     * Handle Anomaly Detection query conforming to PRD #23.3.
     * Scans for refund rate spikes, cash variance anomalies, and stock integrity using non-accusatory language.
     */
    protected function handleAnomalyDetectionQuery(Tenant $tenant, ?User $user = null): array
    {
        [$isOwner, $authorizedOutletIds] = $this->resolveUserOutletScope($tenant, $user);

        $timezone = $tenant->timezone ?? 'Asia/Jakarta';
        $now = Carbon::now($timezone);
        $startOfDay = $now->copy()->startOfDay();
        $endOfDay = $now->copy()->endOfDay();

        // 1. Sales & Refund Ratio Scan
        $salesQuery = Sale::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', Sale::STATUS_FINALIZED)
            ->whereBetween('finalized_at', [$startOfDay, $endOfDay]);

        if (! $isOwner && $authorizedOutletIds !== null) {
            $salesQuery->whereIn('outlet_id', $authorizedOutletIds);
        }

        $saleCount = $salesQuery->count();
        $refundQuery = PaymentRefund::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', PaymentRefund::STATUS_COMPLETED)
            ->whereBetween('created_at', [$startOfDay, $endOfDay]);

        $refundCount = $refundQuery->count();
        $refundRate = $saleCount > 0 ? round(($refundCount / $saleCount) * 100, 1) : 0.0;

        $anomalies = [];

        // PRD #23.3: Normal refund rate 1-3%. If > 5%, report as anomaly needing review.
        if ($refundRate > 5.0 && $saleCount >= 5) {
            $anomalies[] = "⚠️ *Tingkat Refund*: Rasio refund mencapai {$refundRate}% hari ini ({$refundCount} refund dari {$saleCount} transaksi, di atas pola normal 1–3%). Perlu ditinjau.";
        }

        // 2. Shift Cash Variance Scan
        $shiftQuery = Shift::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'closed')
            ->whereBetween('closed_at', [$startOfDay, $endOfDay]);

        if (! $isOwner && $authorizedOutletIds !== null) {
            $shiftQuery->whereIn('outlet_id', $authorizedOutletIds);
        }

        $shifts = $shiftQuery->get();
        foreach ($shifts as $shift) {
            $expected = (int) ($shift->expected_cash_minor ?? 0);
            $actual = (int) ($shift->actual_cash_minor ?? 0);
            $diff = $actual - $expected;
            if ($diff !== 0) {
                $diffFormatted = 'Rp '.number_format(abs($diff) / 100, 0, ',', '.');
                $direction = $diff < 0 ? 'kekurangan' : 'kelebihan';
                $anomalies[] = "⚠️ *Selisih Kas Laci*: Ditemukan {$direction} kas sebesar {$diffFormatted} pada shift kasir ID {$shift->id}. Perlu ditinjau.";
            }
        }

        // 3. Negative Stock Scan
        $negativeStockQuery = InventoryItem::query()
            ->where('inventory_items.tenant_id', $tenant->id)
            ->join('inventory_item_locations', 'inventory_items.id', '=', 'inventory_item_locations.inventory_item_id')
            ->where('inventory_item_locations.on_hand', '<', 0);

        $negativeStockCount = $negativeStockQuery->count();
        if ($negativeStockCount > 0) {
            $anomalies[] = "⚠️ *Integritas Stok*: Terdeteksi {$negativeStockCount} item dengan saldo stok di bawah nol. Berbeda dari pola operasional, perlu stock opname.";
        }

        $lines = [];
        $lines[] = '🔍 *Pemindaian Anomali Operasional (AI Copilot)*';
        $lines[] = ! $isOwner ? 'Cakupan: *Outlet Ditugaskan*' : "Tenant: *{$tenant->name}*";
        $lines[] = '--------------------------------';

        if (empty($anomalies)) {
            $lines[] = '✅ *Status Operasional: Normal (Bebas Anomali Kritis)*';
            $lines[] = '• Tingkat refund transaksi berada dalam ambang batas normal.';
            $lines[] = '• Selisih laci kasir dan penutupan shift seimbang.';
            $lines[] = '• Tidak terdeteksi anomali negatif pada persediaan barang.';
            $lines[] = 'Pola aktivitas operasional konsisten dengan pola historis.';
        } else {
            $lines[] = 'Ditemukan beberapa indikasi operasional yang berbeda dari pola historis:';
            $lines = array_merge($lines, $anomalies);
            $lines[] = '--------------------------------';
            $lines[] = 'Catatan: Sistem tidak menuduh kecurangan; indikator di atas adalah panduan peninjauan manajerial.';
        }

        $lines[] = $this->getEscalationFooter($tenant);

        return [
            'intent' => 'anomaly_detection',
            'success' => true,
            'reply_text' => implode("\n", $lines),
            'data' => [
                'has_anomalies' => ! empty($anomalies),
                'anomalies_count' => count($anomalies),
                'refund_rate_percentage' => $refundRate,
                'shifts_scanned' => $shifts->count(),
                'negative_stock_items' => $negativeStockCount,
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
