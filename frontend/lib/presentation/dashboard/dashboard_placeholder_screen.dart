import 'package:flutter/material.dart';
import '../../core/auth/auth_service.dart';
import '../../core/catalog/catalog_service.dart';
import '../../core/dashboard/dashboard_models.dart';
import '../../core/dashboard/dashboard_service.dart';
import '../../core/inventory/inventory_service.dart';
import '../../core/notification/notification_service.dart';
import '../../core/purchasing/purchasing_service.dart';
import '../../core/shift/shift_service.dart';
import '../../core/tenant/tenant_service.dart';
import '../catalog/catalog_screen.dart';
import '../inventory/inventory_dashboard_screen.dart';
import '../notification/notification_list_screen.dart';
import '../outlet/outlet_management_screen.dart';
import '../purchasing/purchase_order_list_screen.dart';
import '../shift/shift_management_screen.dart';
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
                        _buildQuickActionButtons(),
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

  Widget _buildQuickActionButtons() {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
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
          icon: const Icon(Icons.point_of_sale, size: 20),
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
}
