import 'package:flutter/material.dart';
import '../../core/ai/ai_copilot_service.dart';
import '../../core/auth/auth_service.dart';
import '../../core/catalog/catalog_service.dart';
import '../../core/customer/customer_service.dart';
import '../../core/dashboard/dashboard_models.dart';
import '../../core/dashboard/dashboard_service.dart';
import '../../core/employee/employee_service.dart';
import '../../core/finance/finance_service.dart';
import '../../core/inventory/inventory_service.dart';
import '../../core/loyalty/loyalty_service.dart';
import '../../core/notification/notification_service.dart';
import '../../core/order/order_service.dart';
import '../../core/pos/pos_service.dart';
import '../../core/purchasing/purchasing_service.dart';
import '../../core/recipe/recipe_service.dart';
import '../../core/shift/shift_service.dart';
import '../../core/subscription/subscription_service.dart';
import '../../core/table/table_service.dart';
import '../../core/payment/payment_reconciliation_service.dart';
import '../../core/payment/payment_service.dart';
import '../../core/tenant/tenant_service.dart';
import '../../core/whatsapp/whatsapp_service.dart';
import '../ai/ai_copilot_screen.dart';
import '../catalog/catalog_screen.dart';
import '../customer/customer_list_screen.dart';
import '../employee/employee_management_screen.dart';
import '../finance/finance_overview_screen.dart';
import '../finance/payment_reconciliation_screen.dart';
import '../inventory/inventory_dashboard_screen.dart';
import '../loyalty/loyalty_overview_screen.dart';
import '../notification/notification_list_screen.dart';
import '../order/order_management_screen.dart';
import '../outlet/outlet_management_screen.dart';
import '../platform/payment_gateway_settings_screen.dart';
import '../platform/whatsapp_settings_screen.dart';
import '../pos/pos_screen.dart';
import '../purchasing/purchase_order_list_screen.dart';
import '../recipe/recipe_list_screen.dart';
import '../shift/shift_management_screen.dart';
import '../subscription/subscription_overview_screen.dart';
import '../table/booking_management_screen.dart';
import '../table/table_management_screen.dart';
import '../tenant/business_profile_screen.dart';
import '../tenant/tenant_selection_screen.dart';

class DashboardPlaceholderScreen extends StatefulWidget {
  final TenantService tenantService;
  final AuthService authService;
  final CatalogService? catalogService;
  final InventoryService? inventoryService;
  final PurchasingService? purchasingService;
  final ShiftService? shiftService;
  final DashboardService? dashboardService;
  final NotificationService? notificationService;
  final EmployeeService? employeeService;
  final CustomerService? customerService;
  final TableService? tableService;
  final OrderService? orderService;
  final RecipeService? recipeService;
  final AiCopilotService? copilotService;
  final PosService? posService;
  final FinanceService? financeService;
  final LoyaltyService? loyaltyService;
  final SubscriptionService? subscriptionService;
  final WhatsAppService? whatsAppService;
  final PaymentGatewayService? paymentGatewayService;
  final PaymentReconciliationService? paymentReconciliationService;

  const DashboardPlaceholderScreen({
    super.key,
    required this.tenantService,
    required this.authService,
    this.catalogService,
    this.inventoryService,
    this.purchasingService,
    this.shiftService,
    this.dashboardService,
    this.notificationService,
    this.employeeService,
    this.customerService,
    this.tableService,
    this.orderService,
    this.recipeService,
    this.copilotService,
    this.posService,
    this.financeService,
    this.loyaltyService,
    this.subscriptionService,
    this.whatsAppService,
    this.paymentGatewayService,
    this.paymentReconciliationService,
  });

  @override
  State<DashboardPlaceholderScreen> createState() =>
      _DashboardPlaceholderScreenState();
}

class _DashboardPlaceholderScreenState
    extends State<DashboardPlaceholderScreen> {
  late final DashboardService _dashboardService;
  late final NotificationService _notificationService;
  String _selectedPeriod = 'today';
  String? _selectedOutletId;

  EmployeeService get _employeeService =>
      widget.employeeService ??
      EmployeeService(apiClient: widget.tenantService.apiClient);
  CustomerService get _customerService =>
      widget.customerService ??
      CustomerService(apiClient: widget.tenantService.apiClient);
  TableService get _tableService =>
      widget.tableService ??
      TableService(apiClient: widget.tenantService.apiClient);
  OrderService get _orderService =>
      widget.orderService ??
      OrderService(apiClient: widget.tenantService.apiClient);
  RecipeService get _recipeService =>
      widget.recipeService ??
      RecipeService(apiClient: widget.tenantService.apiClient);
  AiCopilotService get _copilotService =>
      widget.copilotService ??
      AiCopilotService(apiClient: widget.tenantService.apiClient);
  PosService get _posService =>
      widget.posService ??
      PosService(apiClient: widget.tenantService.apiClient);
  FinanceService get _financeService =>
      widget.financeService ??
      FinanceService(apiClient: widget.tenantService.apiClient);
  LoyaltyService get _loyaltyService =>
      widget.loyaltyService ??
      LoyaltyService(apiClient: widget.tenantService.apiClient);
  SubscriptionService get _subscriptionService =>
      widget.subscriptionService ??
      SubscriptionService(apiClient: widget.tenantService.apiClient);
  WhatsAppService get _whatsAppService =>
      widget.whatsAppService ??
      WhatsAppService(apiClient: widget.tenantService.apiClient);
  PaymentGatewayService get _paymentGatewayService =>
      widget.paymentGatewayService ??
      PaymentGatewayService(apiClient: widget.tenantService.apiClient);
  PaymentReconciliationService get _paymentReconciliationService =>
      widget.paymentReconciliationService ??
      PaymentReconciliationService(apiClient: widget.tenantService.apiClient);

  @override
  void initState() {
    super.initState();
    _dashboardService =
        widget.dashboardService ??
        DashboardService(apiClient: widget.tenantService.apiClient);
    _notificationService =
        widget.notificationService ??
        NotificationService(apiClient: widget.tenantService.apiClient);

    _dashboardService.addListener(_onServiceUpdate);
    _notificationService.addListener(_onServiceUpdate);

    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadDashboardData();
      _notificationService.fetchUnreadCount();
    });
  }

  @override
  void dispose() {
    _dashboardService.removeListener(_onServiceUpdate);
    _notificationService.removeListener(_onServiceUpdate);
    super.dispose();
  }

  void _onServiceUpdate() {
    if (mounted) setState(() {});
  }

  Future<void> _loadDashboardData() async {
    await _dashboardService.fetchDashboard(
      period: _selectedPeriod,
      outletId: _selectedOutletId,
    );
  }

  String _formatRupiah(int amountMinor) {
    final isNegative = amountMinor < 0;
    final abs = amountMinor.abs();
    final chars = abs.toString().split('').reversed.toList();
    final buffer = StringBuffer();
    for (int i = 0; i < chars.length; i++) {
      if (i > 0 && i % 3 == 0) buffer.write('.');
      buffer.write(chars[i]);
    }
    final formatted = buffer.toString().split('').reversed.join('');
    return isNegative ? '-Rp $formatted' : 'Rp $formatted';
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: Listenable.merge([widget.tenantService, widget.authService]),
      builder: (context, _) {
        final activeTenant = widget.tenantService.activeTenant;
        final activeOutlet = widget.tenantService.activeOutlet;
        final user = widget.authService.state.user;
        final role = (activeTenant?.role ?? 'owner').toLowerCase();
        final isCashier = role == 'cashier';

        return Scaffold(
          backgroundColor: const Color(0xFF0F172A),
          drawer: _buildAppDrawer(
            context,
            activeTenant,
            activeOutlet,
            user,
            role,
            isCashier,
          ),
          appBar: AppBar(
            title: const Text(
              'SeWaroeng Dashboard',
              style: TextStyle(color: Colors.white),
            ),
            backgroundColor: const Color(0xFF1E293B),
            elevation: 0,
            actions: [
              // Notification Bell with Badge
              IconButton(
                key: const Key('dashboard_notification_bell_button'),
                icon: Badge(
                  isLabelVisible: _notificationService.unreadCount > 0,
                  label: Text('${_notificationService.unreadCount}'),
                  backgroundColor: const Color(0xFFEF4444),
                  child: const Icon(
                    Icons.notifications_outlined,
                    color: Color(0xFF38BDF8),
                  ),
                ),
                tooltip: 'Notifikasi & Peringatan',
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => NotificationListScreen(
                        notificationService: _notificationService,
                        inventoryService: widget.inventoryService,
                        shiftService: widget.shiftService,
                        tenantService: widget.tenantService,
                      ),
                    ),
                  ).then((_) {
                    _notificationService.fetchUnreadCount();
                  });
                },
              ),
              IconButton(
                key: const Key('dashboard_switch_business_button'),
                icon: const Icon(Icons.swap_horiz, color: Color(0xFF38BDF8)),
                tooltip: 'Ganti Bisnis',
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => TenantSelectionScreen(
                        tenantService: widget.tenantService,
                        authService: widget.authService,
                        onTenantSelected: (_) => Navigator.of(context).pop(),
                      ),
                    ),
                  );
                },
              ),
              IconButton(
                key: const Key('dashboard_logout_button'),
                icon: const Icon(Icons.logout, color: Color(0xFFEF4444)),
                tooltip: 'Keluar',
                onPressed: () async {
                  await widget.tenantService.clearContext();
                  await widget.authService.logout();
                },
              ),
            ],
          ),
          body: SafeArea(
            child: RefreshIndicator(
              onRefresh: () async {
                await _loadDashboardData();
                await _notificationService.fetchUnreadCount();
              },
              child: SingleChildScrollView(
                physics: const AlwaysScrollableScrollPhysics(),
                padding: const EdgeInsets.all(24.0),
                child: Center(
                  child: ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 800),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        // Active Context Card
                        _buildActiveContextCard(
                          activeTenant: activeTenant,
                          activeOutlet: activeOutlet,
                          user: user,
                        ),
                        const SizedBox(height: 16),

                        // If not Cashier, display rich operational & financial dashboard
                        if (!isCashier) ...[
                          _buildFilterSection(),
                          const SizedBox(height: 16),
                          if (_dashboardService.isLoading)
                            const Center(
                              child: Padding(
                                padding: EdgeInsets.all(32.0),
                                child: CircularProgressIndicator(
                                  color: Color(0xFF38BDF8),
                                ),
                              ),
                            )
                          else if (_dashboardService.errorMessage != null)
                            _buildErrorBanner(_dashboardService.errorMessage!)
                          else if (_dashboardService.dashboardData != null)
                            _buildDashboardContent(
                              _dashboardService.dashboardData!,
                            ),
                          const SizedBox(height: 24),
                        ],

                        // Quick Operational Navigation Section
                        const Text(
                          'Akses Cepat & Operasional',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        const SizedBox(height: 12),
                        _buildQuickActionButtons(
                          activeOutlet: activeOutlet,
                          role: role,
                          isCashier: isCashier,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  Widget _buildActiveContextCard({
    required dynamic activeTenant,
    required dynamic activeOutlet,
    required dynamic user,
  }) {
    return Card(
      elevation: 4,
      color: const Color(0xFF1E293B),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: const BorderSide(color: Color(0xFF334155)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(24.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                const CircleAvatar(
                  backgroundColor: Color(0xFF0284C7),
                  radius: 24,
                  child: Icon(Icons.store, color: Colors.white, size: 28),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        activeTenant?.name ?? 'Nama Bisnis Belum Dipilih',
                        key: const Key('dashboard_business_name'),
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 20,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      Text(
                        'Slug: ${activeTenant?.slug ?? "-"}',
                        key: const Key('dashboard_business_slug'),
                        style: const TextStyle(
                          color: Color(0xFF94A3B8),
                          fontSize: 13,
                        ),
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 10,
                    vertical: 4,
                  ),
                  decoration: BoxDecoration(
                    color: const Color(0xFF0284C7).withAlpha(50),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: const Color(0xFF0284C7)),
                  ),
                  child: Text(
                    activeTenant?.role?.toUpperCase() ?? 'OWNER',
                    key: const Key('dashboard_user_role'),
                    style: const TextStyle(
                      color: Color(0xFF38BDF8),
                      fontSize: 12,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 20),
            const Divider(color: Color(0xFF334155)),
            const SizedBox(height: 16),

            // Active Outlet Info
            Row(
              children: [
                const Icon(
                  Icons.place_outlined,
                  color: Color(0xFF38BDF8),
                  size: 20,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'Outlet Aktif: ${activeOutlet?.name ?? "Tidak ada outlet"} (${activeOutlet?.code ?? "-"})',
                    key: const Key('dashboard_outlet_name'),
                    style: const TextStyle(
                      color: Color(0xFFCBD5E1),
                      fontSize: 14,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),

            // Logged in User Info
            Row(
              children: [
                const Icon(
                  Icons.person_outline,
                  color: Color(0xFF94A3B8),
                  size: 20,
                ),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    'Pengguna: ${user?.name ?? "-"} (${user?.phoneNumber ?? user?.email ?? "-"})',
                    style: const TextStyle(
                      color: Color(0xFF94A3B8),
                      fontSize: 13,
                    ),
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildFilterSection() {
    return Card(
      color: const Color(0xFF1E293B),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: Color(0xFF334155)),
      ),
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 16.0, vertical: 12.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Filter Periode & Outlet',
              style: TextStyle(
                color: Color(0xFF94A3B8),
                fontSize: 12,
                fontWeight: FontWeight.bold,
              ),
            ),
            const SizedBox(height: 8),
            SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: [
                  _buildPeriodChip('Hari Ini', 'today'),
                  const SizedBox(width: 8),
                  _buildPeriodChip('Kemarin', 'yesterday'),
                  const SizedBox(width: 8),
                  _buildPeriodChip('7 Hari', '7_days'),
                  const SizedBox(width: 8),
                  _buildPeriodChip('30 Hari', '30_days'),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildPeriodChip(String label, String periodKey) {
    final isSelected = _selectedPeriod == periodKey;
    return ChoiceChip(
      label: Text(label),
      selected: isSelected,
      selectedColor: const Color(0xFF0284C7),
      backgroundColor: const Color(0xFF0F172A),
      labelStyle: TextStyle(
        color: isSelected ? Colors.white : const Color(0xFF94A3B8),
        fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
        fontSize: 13,
      ),
      side: BorderSide(
        color: isSelected ? const Color(0xFF38BDF8) : const Color(0xFF334155),
      ),
      onSelected: (selected) {
        if (selected && _selectedPeriod != periodKey) {
          setState(() => _selectedPeriod = periodKey);
          _loadDashboardData();
        }
      },
    );
  }

  Widget _buildErrorBanner(String message) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFFEF4444).withAlpha(40),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFFEF4444)),
      ),
      child: Row(
        children: [
          const Icon(Icons.error_outline, color: Color(0xFFEF4444)),
          const SizedBox(width: 12),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(color: Color(0xFFFCA5A5)),
            ),
          ),
          TextButton(
            onPressed: _loadDashboardData,
            child: const Text('Coba Lagi'),
          ),
        ],
      ),
    );
  }

  Widget _buildDashboardContent(DashboardData data) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Operational Alerts Banner if unread count > 0 or low stock items exist
        if (data.alerts.unreadCount > 0 || data.lowStock.isNotEmpty) ...[
          _buildAlertsBanner(data.alerts, data.lowStock.length),
          const SizedBox(height: 16),
        ],

        // KPI Summary Cards Grid
        _buildKpiGrid(data.kpi),
        const SizedBox(height: 16),

        // Top Products & Low Stock
        _buildTopProductsAndLowStock(data),
        const SizedBox(height: 16),

        // Top Customers & Payment Status
        _buildTopCustomersAndPayment(data),
        const SizedBox(height: 16),

        // Outlet Summary (if multi-outlet available)
        if (data.outletSummary.isNotEmpty) ...[
          _buildOutletSummarySection(data.outletSummary),
          const SizedBox(height: 16),
        ],
      ],
    );
  }

  Widget _buildAlertsBanner(AlertsSummary alerts, int lowStockCount) {
    return InkWell(
      onTap: () {
        Navigator.of(context).push(
          MaterialPageRoute(
            builder: (_) => NotificationListScreen(
              notificationService: _notificationService,
              inventoryService: widget.inventoryService,
              shiftService: widget.shiftService,
              tenantService: widget.tenantService,
            ),
          ),
        );
      },
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: const Color(0xFFF59E0B).withAlpha(30),
          borderRadius: BorderRadius.circular(12),
          border: Border.all(color: const Color(0xFFF59E0B)),
        ),
        child: Row(
          children: [
            const Icon(Icons.warning_amber_rounded, color: Color(0xFFF59E0B), size: 28),
            const SizedBox(width: 12),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Peringatan Operasional Perlu Tindakan',
                    style: TextStyle(
                      color: Color(0xFFFDE68A),
                      fontWeight: FontWeight.bold,
                      fontSize: 14,
                    ),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    '$lowStockCount Item Stok Menipis  •  ${alerts.unreadCount} Notifikasi Belum Dibaca',
                    style: const TextStyle(
                      color: Color(0xFFCBD5E1),
                      fontSize: 12,
                    ),
                  ),
                ],
              ),
            ),
            const Icon(Icons.chevron_right, color: Color(0xFFF59E0B)),
          ],
        ),
      ),
    );
  }

  Widget _buildKpiGrid(DashboardKpis kpi) {
    final hasFinance = kpi.grossProfitMinor != null;

    return LayoutBuilder(
      builder: (context, constraints) {
        final crossAxisCount = constraints.maxWidth > 550 ? 3 : 2;
        return Wrap(
          spacing: 12,
          runSpacing: 12,
          children: [
            _buildKpiCard(
              title: 'Penjualan Bersih',
              value: _formatRupiah(kpi.netSalesMinor),
              subtitle: 'Kotor: ${_formatRupiah(kpi.grossSalesMinor)}',
              icon: Icons.payments_outlined,
              iconColor: const Color(0xFF38BDF8),
              width: (constraints.maxWidth - (crossAxisCount - 1) * 12) /
                  crossAxisCount,
            ),
            _buildKpiCard(
              title: 'Total Transaksi',
              value: '${kpi.transactionCount}',
              subtitle: 'Transaksi Selesai',
              icon: Icons.receipt_long_outlined,
              iconColor: const Color(0xFF34D399),
              width: (constraints.maxWidth - (crossAxisCount - 1) * 12) /
                  crossAxisCount,
            ),
            _buildKpiCard(
              title: 'Rata-rata Order (AOV)',
              value: _formatRupiah(kpi.averageOrderValueMinor),
              subtitle: 'Nilai per Transaksi',
              icon: Icons.analytics_outlined,
              iconColor: const Color(0xFFA78BFA),
              width: (constraints.maxWidth - (crossAxisCount - 1) * 12) /
                  crossAxisCount,
            ),
            if (hasFinance) ...[
              _buildKpiCard(
                title: 'Laba Kotor',
                value: _formatRupiah(kpi.grossProfitMinor ?? 0),
                subtitle: 'HPP: ${_formatRupiah(kpi.cogsMinor ?? 0)}',
                icon: Icons.account_balance_wallet_outlined,
                iconColor: const Color(0xFFFBBF24),
                width: (constraints.maxWidth - (crossAxisCount - 1) * 12) /
                    crossAxisCount,
              ),
              _buildKpiCard(
                title: 'Arus Kas Bersih',
                value: _formatRupiah(kpi.netCashflowMinor ?? 0),
                subtitle: 'Penerimaan - Pengeluaran',
                icon: Icons.compare_arrows_outlined,
                iconColor: const Color(0xFFF472B6),
                width: (constraints.maxWidth - (crossAxisCount - 1) * 12) /
                    crossAxisCount,
              ),
            ],
          ],
        );
      },
    );
  }

  Widget _buildKpiCard({
    required String title,
    required String value,
    required String subtitle,
    required IconData icon,
    required Color iconColor,
    required double width,
  }) {
    return Container(
      width: width,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: const Color(0xFF1E293B),
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: const Color(0xFF334155)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Expanded(
                child: Text(
                  title,
                  style: const TextStyle(
                    color: Color(0xFF94A3B8),
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                  ),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              const SizedBox(width: 4),
              Icon(icon, color: iconColor, size: 20),
            ],
          ),
          const SizedBox(height: 10),
          Text(
            value,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 18,
              fontWeight: FontWeight.bold,
            ),
          ),
          const SizedBox(height: 4),
          Text(
            subtitle,
            style: const TextStyle(
              color: Color(0xFF64748B),
              fontSize: 11,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildTopProductsAndLowStock(DashboardData data) {
    return Column(
      children: [
        // Top Products
        Card(
          color: const Color(0xFF1E293B),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: const BorderSide(color: Color(0xFF334155)),
          ),
          child: Padding(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Row(
                  children: [
                    Icon(Icons.leaderboard, color: Color(0xFF38BDF8), size: 20),
                    SizedBox(width: 8),
                    Text(
                      'Produk Terlaris',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 15,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                if (data.topProducts.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 12.0),
                    child: Text(
                      'Belum ada penjualan pada periode ini.',
                      style: TextStyle(color: Color(0xFF64748B), fontSize: 13),
                    ),
                  )
                else
                  ...data.topProducts.take(5).map((item) {
                    return Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6.0),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              item.productName,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 13,
                              ),
                            ),
                          ),
                          Text(
                            '${item.unitsSold.toInt()} terjual',
                            style: const TextStyle(
                              color: Color(0xFF94A3B8),
                              fontSize: 12,
                            ),
                          ),
                          const SizedBox(width: 16),
                          Text(
                            _formatRupiah(item.revenueMinor),
                            style: const TextStyle(
                              color: Color(0xFF38BDF8),
                              fontWeight: FontWeight.w600,
                              fontSize: 13,
                            ),
                          ),
                        ],
                      ),
                    );
                  }),
              ],
            ),
          ),
        ),
        const SizedBox(height: 12),

        // Low Stock Items
        Card(
          color: const Color(0xFF1E293B),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: const BorderSide(color: Color(0xFF334155)),
          ),
          child: Padding(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    const Row(
                      children: [
                        Icon(Icons.warning_amber,
                            color: Color(0xFFF59E0B), size: 20),
                        SizedBox(width: 8),
                        Text(
                          'Stok Menipis / Perlu Reorder',
                          style: TextStyle(
                            color: Colors.white,
                            fontSize: 15,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ],
                    ),
                    if (data.lowStock.isNotEmpty)
                      Text(
                        '${data.lowStock.length} Item',
                        style: const TextStyle(
                          color: Color(0xFFF59E0B),
                          fontWeight: FontWeight.bold,
                          fontSize: 12,
                        ),
                      ),
                  ],
                ),
                const SizedBox(height: 12),
                if (data.lowStock.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 12.0),
                    child: Text(
                      'Semua stok aman dan di atas batas minimum.',
                      style: TextStyle(color: Color(0xFF64748B), fontSize: 13),
                    ),
                  )
                else
                  ...data.lowStock.take(5).map((item) {
                    return Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6.0),
                      child: Row(
                        children: [
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  item.itemName,
                                  style: const TextStyle(
                                    color: Colors.white,
                                    fontSize: 13,
                                  ),
                                ),
                                Text(
                                  'SKU: ${item.sku}',
                                  style: const TextStyle(
                                    color: Color(0xFF64748B),
                                    fontSize: 11,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 2,
                            ),
                            decoration: BoxDecoration(
                              color: const Color(0xFFEF4444).withAlpha(40),
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: Text(
                              'Sisa: ${item.available.toInt()} (Min: ${item.reorderLevel.toInt()})',
                              style: const TextStyle(
                                color: Color(0xFFF87171),
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                          ),
                        ],
                      ),
                    );
                  }),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildTopCustomersAndPayment(DashboardData data) {
    return Column(
      children: [
        // Top Customers
        Card(
          color: const Color(0xFF1E293B),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: const BorderSide(color: Color(0xFF334155)),
          ),
          child: Padding(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Row(
                  children: [
                    Icon(Icons.people_outline,
                        color: Color(0xFF38BDF8), size: 20),
                    SizedBox(width: 8),
                    Text(
                      'Pelanggan Teratas',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 15,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                if (data.topCustomers.isEmpty)
                  const Padding(
                    padding: EdgeInsets.symmetric(vertical: 12.0),
                    child: Text(
                      'Belum ada transaksi pelanggan terdaftar pada periode ini.',
                      style: TextStyle(color: Color(0xFF64748B), fontSize: 13),
                    ),
                  )
                else
                  ...data.topCustomers.take(5).map((cust) {
                    return Padding(
                      padding: const EdgeInsets.symmetric(vertical: 6.0),
                      child: Row(
                        children: [
                          Expanded(
                            child: Text(
                              cust.customerName,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 13,
                              ),
                            ),
                          ),
                          Text(
                            '${cust.transactionCount}x transaksi',
                            style: const TextStyle(
                              color: Color(0xFF94A3B8),
                              fontSize: 12,
                            ),
                          ),
                          const SizedBox(width: 16),
                          Text(
                            _formatRupiah(cust.totalSpendMinor),
                            style: const TextStyle(
                              color: Color(0xFF34D399),
                              fontWeight: FontWeight.w600,
                              fontSize: 13,
                            ),
                          ),
                        ],
                      ),
                    );
                  }),
              ],
            ),
          ),
        ),
        const SizedBox(height: 12),

        // Payment Status Breakdown
        Card(
          color: const Color(0xFF1E293B),
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(12),
            side: const BorderSide(color: Color(0xFF334155)),
          ),
          child: Padding(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Row(
                  children: [
                    Icon(Icons.credit_card,
                        color: Color(0xFF38BDF8), size: 20),
                    SizedBox(width: 8),
                    Text(
                      'Status Pembayaran',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 15,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceAround,
                  children: [
                    _buildPaymentStatusItem(
                      'Berhasil',
                      (data.paymentSummary['succeeded'] as num?)?.toInt() ?? 0,
                      const Color(0xFF34D399),
                    ),
                    _buildPaymentStatusItem(
                      'Memproses',
                      (data.paymentSummary['processing'] as num?)?.toInt() ?? 0,
                      const Color(0xFF38BDF8),
                    ),
                    _buildPaymentStatusItem(
                      'Menunggu',
                      (data.paymentSummary['pending'] as num?)?.toInt() ?? 0,
                      const Color(0xFFFBBF24),
                    ),
                    _buildPaymentStatusItem(
                      'Gagal',
                      (data.paymentSummary['failed'] as num?)?.toInt() ?? 0,
                      const Color(0xFFEF4444),
                    ),
                    _buildPaymentStatusItem(
                      'Refund',
                      ((data.paymentSummary['refunded'] as num?)?.toInt() ?? 0) +
                          ((data.paymentSummary['partially_refunded'] as num?)?.toInt() ?? 0),
                      const Color(0xFFA78BFA),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }

  Widget _buildPaymentStatusItem(String label, int count, Color color) {
    return Column(
      children: [
        Text(
          '$count',
          style: TextStyle(
            color: color,
            fontSize: 18,
            fontWeight: FontWeight.bold,
          ),
        ),
        const SizedBox(height: 2),
        Text(
          label,
          style: const TextStyle(
            color: Color(0xFF94A3B8),
            fontSize: 11,
          ),
        ),
      ],
    );
  }

  Widget _buildOutletSummarySection(List<OutletSummaryItem> outlets) {
    return Card(
      color: const Color(0xFF1E293B),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: Color(0xFF334155)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Row(
              children: [
                Icon(Icons.storefront, color: Color(0xFF38BDF8), size: 20),
                SizedBox(width: 8),
                Text(
                  'Kinerja Antar Outlet',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 15,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            ...outlets.map((outlet) {
              return Padding(
                padding: const EdgeInsets.symmetric(vertical: 8.0),
                child: Row(
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            outlet.outletName,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 13,
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                          Text(
                            '${outlet.transactionCount} transaksi  •  AOV ${_formatRupiah(outlet.aovMinor)}',
                            style: const TextStyle(
                              color: Color(0xFF64748B),
                              fontSize: 11,
                            ),
                          ),
                        ],
                      ),
                    ),
                    Text(
                      _formatRupiah(outlet.grossSalesMinor),
                      style: const TextStyle(
                        color: Color(0xFF38BDF8),
                        fontWeight: FontWeight.bold,
                        fontSize: 13,
                      ),
                    ),
                  ],
                ),
              );
            }),
          ],
        ),
      ),
    );
  }

  Widget _buildQuickActionButtons({
    dynamic activeOutlet,
    String role = 'owner',
    bool isCashier = false,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Primary POS Action
        ElevatedButton.icon(
          key: const Key('dashboard_pos_button'),
          icon: const Icon(Icons.point_of_sale, size: 22),
          label: const Text(
            'Buka Kasir POS',
            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
          ),
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF10B981),
            foregroundColor: Colors.white,
            padding: const EdgeInsets.symmetric(vertical: 18),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
          onPressed: () {
            final outletId = activeOutlet?.id ?? 'default';
            final outletName = activeOutlet?.name ?? 'Outlet Utama';
            Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => PosScreen(
                  posService: _posService,
                  customerService: _customerService,
                  outletId: outletId,
                  outletName: outletName,
                ),
              ),
            );
          },
        ),
        const SizedBox(height: 12),

        // Catalog Quick Action
        ElevatedButton.icon(
          key: const Key('dashboard_catalog_button'),
          icon: const Icon(Icons.inventory_2, size: 20),
          label: const Text(
            'Katalog Produk & Menu',
            style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
          ),
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF0F766E),
            foregroundColor: Colors.white,
            padding: const EdgeInsets.symmetric(vertical: 16),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
          onPressed: () {
            Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => CatalogScreen(
                  catalogService: widget.catalogService ??
                      CatalogService(
                        apiClient: widget.tenantService.apiClient,
                      ),
                  tenantService: widget.tenantService,
                ),
              ),
            );
          },
        ),
        const SizedBox(height: 12),

        // Inventory Quick Action
        ElevatedButton.icon(
          key: const Key('dashboard_inventory_button'),
          icon: const Icon(Icons.shelves, size: 20),
          label: const Text(
            'Manajemen Inventory & Stok',
            style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
          ),
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF4338CA),
            foregroundColor: Colors.white,
            padding: const EdgeInsets.symmetric(vertical: 16),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
          onPressed: () {
            Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => InventoryDashboardScreen(
                  inventoryService: widget.inventoryService ??
                      InventoryService(
                        apiClient: widget.tenantService.apiClient,
                      ),
                ),
              ),
            );
          },
        ),
        const SizedBox(height: 12),

        // Purchasing Quick Action
        ElevatedButton.icon(
          key: const Key('dashboard_purchasing_button'),
          icon: const Icon(Icons.shopping_bag, size: 20),
          label: const Text(
            'Pengadaan & Pembelian (PO & Supplier)',
            style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
          ),
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF0369A1),
            foregroundColor: Colors.white,
            padding: const EdgeInsets.symmetric(vertical: 16),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
          onPressed: () {
            final inv = widget.inventoryService ??
                InventoryService(apiClient: widget.tenantService.apiClient);
            final pur = widget.purchasingService ??
                PurchasingService(apiClient: widget.tenantService.apiClient);
            Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => PurchaseOrderListScreen(
                  purchasingService: pur,
                  inventoryService: inv,
                ),
              ),
            );
          },
        ),
        const SizedBox(height: 12),

        // Shift & Cash Drawer Management Quick Action
        ElevatedButton.icon(
          key: const Key('dashboard_shift_management_button'),
          icon: const Icon(Icons.schedule, size: 20),
          label: const Text(
            'Manajemen Shift & Laci Kasir',
            style: TextStyle(fontSize: 15, fontWeight: FontWeight.bold),
          ),
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF047857),
            foregroundColor: Colors.white,
            padding: const EdgeInsets.symmetric(vertical: 16),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
          onPressed: () {
            final sft = widget.shiftService ??
                ShiftService(apiClient: widget.tenantService.apiClient);
            Navigator.of(context).push(
              MaterialPageRoute(
                builder: (_) => ShiftManagementScreen(
                  shiftService: sft,
                  tenantService: widget.tenantService,
                ),
              ),
            );
          },
        ),
        const SizedBox(height: 12),

        // Row: Orders & Tables
        Row(
          children: [
            Expanded(
              child: ElevatedButton.icon(
                key: const Key('dashboard_orders_button'),
                icon: const Icon(Icons.receipt_long, size: 18),
                label: const Text('Pesanan Online & QR'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF1E293B),
                  foregroundColor: const Color(0xFFF59E0B),
                  side: const BorderSide(color: Color(0xFF334155)),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => OrderManagementScreen(
                        orderService: _orderService,
                        tenantService: widget.tenantService,
                      ),
                    ),
                  );
                },
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: ElevatedButton.icon(
                key: const Key('dashboard_tables_button'),
                icon: const Icon(Icons.table_restaurant, size: 18),
                label: const Text('Meja & Dine-In'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF1E293B),
                  foregroundColor: const Color(0xFF06B6D4),
                  side: const BorderSide(color: Color(0xFF334155)),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => TableManagementScreen(
                        tableService: _tableService,
                        tenantService: widget.tenantService,
                      ),
                    ),
                  );
                },
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),

        // Row: Bookings & Recipes
        Row(
          children: [
            Expanded(
              child: ElevatedButton.icon(
                key: const Key('dashboard_bookings_button'),
                icon: const Icon(Icons.bookmark_border, size: 18),
                label: const Text('Reservasi Meja'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF1E293B),
                  foregroundColor: const Color(0xFF8B5CF6),
                  side: const BorderSide(color: Color(0xFF334155)),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => BookingManagementScreen(
                        tableService: _tableService,
                        tenantService: widget.tenantService,
                      ),
                    ),
                  );
                },
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: ElevatedButton.icon(
                key: const Key('dashboard_recipes_button'),
                icon: const Icon(Icons.restaurant_menu, size: 18),
                label: const Text('Resep & HPP BOM'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF1E293B),
                  foregroundColor: const Color(0xFFD97706),
                  side: const BorderSide(color: Color(0xFF334155)),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => RecipeListScreen(
                        recipeService: _recipeService,
                      ),
                    ),
                  );
                },
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),

        // Row: Customers & AI Copilot
        Row(
          children: [
            Expanded(
              child: ElevatedButton.icon(
                key: const Key('dashboard_customers_button'),
                icon: const Icon(Icons.people_alt_outlined, size: 18),
                label: const Text('Pelanggan CRM 360'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF1E293B),
                  foregroundColor: const Color(0xFF0D9488),
                  side: const BorderSide(color: Color(0xFF334155)),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => CustomerListScreen(
                        customerService: _customerService,
                      ),
                    ),
                  );
                },
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: ElevatedButton.icon(
                key: const Key('dashboard_ai_copilot_button'),
                icon: const Icon(Icons.auto_awesome, size: 18),
                label: const Text('AI Copilot'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF1E293B),
                  foregroundColor: const Color(0xFFA855F7),
                  side: const BorderSide(color: Color(0xFF334155)),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => AiCopilotScreen(
                        copilotService: _copilotService,
                      ),
                    ),
                  );
                },
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),

        // If not cashier, Row: Employee & Finance
        if (!isCashier) ...[
          Row(
            children: [
              Expanded(
                child: ElevatedButton.icon(
                  key: const Key('dashboard_employees_button'),
                  icon: const Icon(Icons.badge_outlined, size: 18),
                  label: const Text('Karyawan & Staf'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF1E293B),
                    foregroundColor: const Color(0xFF38BDF8),
                    side: const BorderSide(color: Color(0xFF334155)),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                  onPressed: () {
                    Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => EmployeeManagementScreen(
                          employeeService: _employeeService,
                          tenantService: widget.tenantService,
                        ),
                      ),
                    );
                  },
                ),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: ElevatedButton.icon(
                  key: const Key('dashboard_finance_button'),
                  icon: const Icon(Icons.account_balance_wallet, size: 18),
                  label: const Text('Laporan Keuangan'),
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF1E293B),
                    foregroundColor: const Color(0xFFEAB308),
                    side: const BorderSide(color: Color(0xFF334155)),
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                  onPressed: () {
                    Navigator.of(context).push(
                      MaterialPageRoute(
                        builder: (_) => FinanceOverviewScreen(
                          financeService: _financeService,
                          reconciliationService: _paymentReconciliationService,
                          userRole: role,
                          outletId: activeOutlet?.id,
                        ),
                      ),
                    );
                  },
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
        ],

        // Business & Outlet Management Shortcuts
        Row(
          children: [
            Expanded(
              child: ElevatedButton.icon(
                key: const Key('dashboard_business_profile_button'),
                icon: const Icon(Icons.settings, size: 18),
                label: const Text('Profil Bisnis'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF1E293B),
                  foregroundColor: const Color(0xFF38BDF8),
                  side: const BorderSide(color: Color(0xFF334155)),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => BusinessProfileScreen(
                        tenantService: widget.tenantService,
                      ),
                    ),
                  );
                },
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: ElevatedButton.icon(
                key: const Key('dashboard_manage_outlets_button'),
                icon: const Icon(Icons.storefront, size: 18),
                label: const Text('Kelola Outlet'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF1E293B),
                  foregroundColor: const Color(0xFF38BDF8),
                  side: const BorderSide(color: Color(0xFF334155)),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                  ),
                ),
                onPressed: () {
                  Navigator.of(context).push(
                    MaterialPageRoute(
                      builder: (_) => OutletManagementScreen(
                        tenantService: widget.tenantService,
                      ),
                    ),
                  );
                },
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildAppDrawer(
    BuildContext context,
    dynamic activeTenant,
    dynamic activeOutlet,
    dynamic user,
    String role,
    bool isCashier,
  ) {
    return Drawer(
      backgroundColor: const Color(0xFF0F172A),
      child: SafeArea(
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            // Drawer Header
            Container(
              padding: const EdgeInsets.all(20),
              color: const Color(0xFF1E293B),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      const CircleAvatar(
                        backgroundColor: Color(0xFF0284C7),
                        radius: 20,
                        child: Icon(Icons.store, color: Colors.white, size: 22),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              activeTenant?.name ?? 'SeWaroeng',
                              style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.bold,
                                fontSize: 16,
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                            Text(
                              activeOutlet?.name ?? 'Belum ada outlet',
                              style: const TextStyle(
                                color: Color(0xFF94A3B8),
                                fontSize: 12,
                              ),
                              maxLines: 1,
                              overflow: TextOverflow.ellipsis,
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: const Color(0xFF0284C7).withAlpha(50),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFF0284C7)),
                    ),
                    child: Text(
                      'PERAN: ${role.toUpperCase()}',
                      style: const TextStyle(
                        color: Color(0xFF38BDF8),
                        fontSize: 10,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ],
              ),
            ),

            const SizedBox(height: 8),

            // Section 1: Operasional
            _buildDrawerSectionHeader('OPERASIONAL & KASIR'),
            _buildDrawerTile(
              key: const Key('drawer_pos_button'),
              icon: Icons.point_of_sale,
              title: 'Kasir POS',
              color: const Color(0xFF10B981),
              onTap: () {
                Navigator.pop(context);
                final outletId = activeOutlet?.id ?? 'default';
                final outletName = activeOutlet?.name ?? 'Outlet Utama';
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => PosScreen(
                      posService: _posService,
                      customerService: _customerService,
                      outletId: outletId,
                      outletName: outletName,
                    ),
                  ),
                );
              },
            ),
            _buildDrawerTile(
              key: const Key('drawer_orders_button'),
              icon: Icons.receipt_long,
              title: 'Pesanan & QR Meja',
              color: const Color(0xFFF59E0B),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => OrderManagementScreen(
                      orderService: _orderService,
                      tenantService: widget.tenantService,
                    ),
                  ),
                );
              },
            ),
            _buildDrawerTile(
              key: const Key('drawer_tables_button'),
              icon: Icons.table_restaurant,
              title: 'Manajemen Meja',
              color: const Color(0xFF06B6D4),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => TableManagementScreen(
                      tableService: _tableService,
                      tenantService: widget.tenantService,
                    ),
                  ),
                );
              },
            ),
            _buildDrawerTile(
              key: const Key('drawer_bookings_button'),
              icon: Icons.bookmark_border,
              title: 'Reservasi & Booking',
              color: const Color(0xFF8B5CF6),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => BookingManagementScreen(
                      tableService: _tableService,
                      tenantService: widget.tenantService,
                    ),
                  ),
                );
              },
            ),
            _buildDrawerTile(
              key: const Key('drawer_shifts_button'),
              icon: Icons.schedule,
              title: 'Shift & Laci Kasir',
              color: const Color(0xFF047857),
              onTap: () {
                Navigator.pop(context);
                final sft = widget.shiftService ??
                    ShiftService(apiClient: widget.tenantService.apiClient);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => ShiftManagementScreen(
                      shiftService: sft,
                      tenantService: widget.tenantService,
                    ),
                  ),
                );
              },
            ),

            const Divider(color: Color(0xFF334155)),

            // Section 2: Produk & Persediaan
            _buildDrawerSectionHeader('PRODUK & PERSEDIAAN'),
            _buildDrawerTile(
              key: const Key('drawer_catalog_button'),
              icon: Icons.inventory_2,
              title: 'Katalog Produk & Menu',
              color: const Color(0xFF0F766E),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => CatalogScreen(
                      catalogService: widget.catalogService ??
                          CatalogService(
                            apiClient: widget.tenantService.apiClient,
                          ),
                      tenantService: widget.tenantService,
                    ),
                  ),
                );
              },
            ),
            _buildDrawerTile(
              key: const Key('drawer_recipes_button'),
              icon: Icons.restaurant_menu,
              title: 'Resep, BOM & HPP',
              color: const Color(0xFFD97706),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => RecipeListScreen(
                      recipeService: _recipeService,
                    ),
                  ),
                );
              },
            ),
            _buildDrawerTile(
              key: const Key('drawer_inventory_button'),
              icon: Icons.shelves,
              title: 'Inventaris & Stok',
              color: const Color(0xFF4338CA),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => InventoryDashboardScreen(
                      inventoryService: widget.inventoryService ??
                          InventoryService(
                            apiClient: widget.tenantService.apiClient,
                          ),
                    ),
                  ),
                );
              },
            ),
            _buildDrawerTile(
              key: const Key('drawer_purchasing_button'),
              icon: Icons.shopping_bag,
              title: 'Pengadaan / PO Supplier',
              color: const Color(0xFF0369A1),
              onTap: () {
                Navigator.pop(context);
                final inv = widget.inventoryService ??
                    InventoryService(apiClient: widget.tenantService.apiClient);
                final pur = widget.purchasingService ??
                    PurchasingService(apiClient: widget.tenantService.apiClient);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => PurchaseOrderListScreen(
                      purchasingService: pur,
                      inventoryService: inv,
                    ),
                  ),
                );
              },
            ),

            const Divider(color: Color(0xFF334155)),

            // Section 3: Pelanggan & CRM
            _buildDrawerSectionHeader('PELANGGAN & CRM'),
            _buildDrawerTile(
              key: const Key('drawer_customers_button'),
              icon: Icons.people_alt_outlined,
              title: 'Customer CRM & 360',
              color: const Color(0xFF0D9488),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => CustomerListScreen(
                      customerService: _customerService,
                    ),
                  ),
                );
              },
            ),
            _buildDrawerTile(
              key: const Key('drawer_loyalty_button'),
              icon: Icons.card_giftcard,
              title: 'Program Loyalitas & Poin',
              color: const Color(0xFFEC4899),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => LoyaltyOverviewScreen(
                      loyaltyService: _loyaltyService,
                    ),
                  ),
                );
              },
            ),

            const Divider(color: Color(0xFF334155)),

            // Section 4: Bisnis, Keuangan & AI
            _buildDrawerSectionHeader('BISNIS & KEUANGAN'),
            if (!isCashier) ...[
              _buildDrawerTile(
                key: const Key('drawer_finance_button'),
                icon: Icons.account_balance_wallet,
                title: 'Laporan Keuangan',
                color: const Color(0xFFEAB308),
                onTap: () {
                  Navigator.pop(context);
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => FinanceOverviewScreen(
                        financeService: _financeService,
                        reconciliationService: _paymentReconciliationService,
                        userRole: role,
                        outletId: activeOutlet?.id,
                      ),
                    ),
                  );
                },
              ),
              _buildDrawerTile(
                key: const Key('drawer_payment_reconciliation_button'),
                icon: Icons.compare_arrows,
                title: 'Rekonsiliasi Pembayaran',
                color: const Color(0xFF10B981),
                onTap: () {
                  Navigator.pop(context);
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => PaymentReconciliationScreen(
                        reconciliationService: _paymentReconciliationService,
                      ),
                    ),
                  );
                },
              ),
            ],
            _buildDrawerTile(
              key: const Key('drawer_ai_copilot_button'),
              icon: Icons.auto_awesome,
              title: 'AI Business Copilot',
              color: const Color(0xFFA855F7),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => AiCopilotScreen(
                      copilotService: _copilotService,
                    ),
                  ),
                );
              },
            ),

            const Divider(color: Color(0xFF334155)),

            // Section 5: Pengaturan & Outlet
            _buildDrawerSectionHeader('PENGATURAN'),
            if (!isCashier) ...[
              _buildDrawerTile(
                key: const Key('drawer_employees_button'),
                icon: Icons.badge_outlined,
                title: 'Karyawan & Staf',
                color: const Color(0xFF38BDF8),
                onTap: () {
                  Navigator.pop(context);
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => EmployeeManagementScreen(
                        employeeService: _employeeService,
                        tenantService: widget.tenantService,
                      ),
                    ),
                  );
                },
              ),
              _buildDrawerTile(
                key: const Key('drawer_outlets_button'),
                icon: Icons.storefront,
                title: 'Kelola Outlet',
                color: const Color(0xFF38BDF8),
                onTap: () {
                  Navigator.pop(context);
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => OutletManagementScreen(
                        tenantService: widget.tenantService,
                      ),
                    ),
                  );
                },
              ),
              _buildDrawerTile(
                key: const Key('drawer_business_profile_button'),
                icon: Icons.settings,
                title: 'Profil Bisnis',
                color: const Color(0xFF38BDF8),
                onTap: () {
                  Navigator.pop(context);
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => BusinessProfileScreen(
                        tenantService: widget.tenantService,
                      ),
                    ),
                  );
                },
              ),
              _buildDrawerTile(
                key: const Key('drawer_whatsapp_settings_button'),
                icon: Icons.chat_outlined,
                title: 'Integrasi WhatsApp',
                color: const Color(0xFF25D366),
                onTap: () {
                  Navigator.pop(context);
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => WhatsAppSettingsScreen(
                        whatsAppService: _whatsAppService,
                      ),
                    ),
                  );
                },
              ),
              _buildDrawerTile(
                key: const Key('drawer_payment_gateway_button'),
                icon: Icons.account_balance,
                title: 'Gateway Pembayaran (Admin)',
                color: const Color(0xFF6366F1),
                onTap: () {
                  Navigator.pop(context);
                  Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => PaymentGatewaySettingsScreen(
                        paymentService: _paymentGatewayService,
                        initialTenantId: activeTenant?.id,
                      ),
                    ),
                  );
                },
              ),
            ],
            _buildDrawerTile(
              key: const Key('drawer_subscription_button'),
              icon: Icons.verified_user_outlined,
              title: 'Langganan & Kuota',
              color: const Color(0xFF38BDF8),
              onTap: () {
                Navigator.pop(context);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => SubscriptionOverviewScreen(
                      subscriptionService: _subscriptionService,
                    ),
                  ),
                );
              },
            ),

            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }

  Widget _buildDrawerSectionHeader(String title) {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Text(
        title,
        style: const TextStyle(
          color: Color(0xFF64748B),
          fontSize: 11,
          fontWeight: FontWeight.bold,
          letterSpacing: 1.1,
        ),
      ),
    );
  }

  Widget _buildDrawerTile({
    required Key key,
    required IconData icon,
    required String title,
    required Color color,
    required VoidCallback onTap,
  }) {
    return ListTile(
      key: key,
      leading: Icon(icon, color: color, size: 22),
      title: Text(
        title,
        style: const TextStyle(color: Colors.white, fontSize: 14),
      ),
      dense: true,
      onTap: onTap,
    );
  }
}

