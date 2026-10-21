import 'package:flutter/material.dart';
import 'package:frontend/core/finance/finance_models.dart';
import 'package:frontend/core/finance/finance_service.dart';
import 'package:frontend/core/payment/payment_reconciliation_service.dart';
import 'package:frontend/presentation/finance/expense_detail_sheet.dart';
import 'package:frontend/presentation/finance/expense_form_dialog.dart';
import 'package:frontend/presentation/finance/payment_reconciliation_screen.dart';

class FinanceOverviewScreen extends StatefulWidget {
  final FinanceService financeService;
  final PaymentReconciliationService? reconciliationService;
  final String userRole; // 'owner', 'manager', 'accountant', 'cashier'
  final String? outletId;

  const FinanceOverviewScreen({
    super.key,
    required this.financeService,
    this.reconciliationService,
    this.userRole = 'owner',
    this.outletId,
  });

  @override
  State<FinanceOverviewScreen> createState() => _FinanceOverviewScreenState();
}

class _FinanceOverviewScreenState extends State<FinanceOverviewScreen> with SingleTickerProviderStateMixin {
  late TabController _tabController;
  String _selectedPeriod = 'this_month'; // 'today', 'this_week', 'this_month'
  String _expenseFilter = 'all'; // 'all', 'posted', 'reversed'

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 4, vsync: this);
    _tabController.addListener(() {
      if (mounted) setState(() {});
    });
    if (_isAuthorized) {
      _loadData();
    }
  }

  bool get _isAuthorized => widget.userRole != 'cashier';

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  Future<void> _loadData() async {
    await Future.wait([
      widget.financeService.fetchProfitLoss(outletId: widget.outletId, period: _selectedPeriod, compare: true),
      widget.financeService.fetchCashFlow(outletId: widget.outletId, period: _selectedPeriod),
      widget.financeService.fetchExpenses(outletId: widget.outletId),
      widget.financeService.fetchBalanceSheet(outletId: widget.outletId),
    ]);
  }

  void _onPeriodChanged(String period) {
    setState(() => _selectedPeriod = period);
    widget.financeService.fetchProfitLoss(outletId: widget.outletId, period: period, compare: true);
    widget.financeService.fetchCashFlow(outletId: widget.outletId, period: period);
    widget.financeService.fetchBalanceSheet(outletId: widget.outletId);
  }

  Future<void> _handleExport(String format) async {
    final currentTab = _tabController.index;
    String reportType;
    String reportName;

    switch (currentTab) {
      case 0:
        reportType = 'profit-loss';
        reportName = 'Laba Rugi';
        break;
      case 1:
        reportType = 'cash-flow';
        reportName = 'Arus Kas';
        break;
      case 2:
        reportType = 'profit-loss';
        reportName = 'Beban Operasional';
        break;
      case 3:
        reportType = 'balance-sheet';
        reportName = 'Neraca Keuangan';
        break;
      default:
        reportType = 'profit-loss';
        reportName = 'Keuangan';
    }

    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Mengunduh laporan $reportName (${format.toUpperCase()})...')),
    );

    final bytes = await widget.financeService.exportReport(
      reportType: reportType,
      format: format,
      outletId: widget.outletId,
      period: _selectedPeriod,
    );

    if (!mounted) return;

    if (bytes != null && bytes.isNotEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Berhasil mengunduh $reportName (${bytes.length} bytes).'),
          backgroundColor: Colors.green,
        ),
      );
    } else {
      final err = widget.financeService.errorMessage ?? 'Gagal mengunduh laporan.';
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(err), backgroundColor: Colors.red),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!_isAuthorized) {
      return Scaffold(
        appBar: AppBar(title: const Text('Keuangan')),
        body: Center(
          child: Padding(
            padding: const EdgeInsets.all(24.0),
            child: Column(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.lock_outline, size: 64, color: Colors.grey.shade400),
                const SizedBox(height: 16),
                const Text(
                  'Akses Terbatas',
                  style: TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                ),
                const SizedBox(height: 8),
                const Text(
                  'Modul keuangan dan buku kas hanya dapat diakses oleh Pemilik Usaha, Manajer, atau Akuntan.',
                  textAlign: TextAlign.center,
                  style: TextStyle(color: Colors.grey),
                ),
              ],
            ),
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Keuangan & Laba Rugi'),
        actions: [
          IconButton(
            key: const Key('open_reconciliation_button'),
            icon: const Icon(Icons.compare_arrows, color: Color(0xFF10B981)),
            tooltip: 'Rekonsiliasi Pembayaran Digital',
            onPressed: () {
              final recService = widget.reconciliationService ??
                  PaymentReconciliationService(
                    apiClient: widget.financeService.apiClient,
                  );
              Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (_) => PaymentReconciliationScreen(
                    reconciliationService: recService,
                  ),
                ),
              );
            },
          ),
          PopupMenuButton<String>(
            key: const Key('export_report_button'),
            icon: const Icon(Icons.file_download_outlined),
            tooltip: 'Export Laporan',
            onSelected: _handleExport,
            itemBuilder: (context) => const [
              PopupMenuItem(
                value: 'pdf',
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.picture_as_pdf, color: Colors.red, size: 18),
                    SizedBox(width: 8),
                    Text('PDF'),
                  ],
                ),
              ),
              PopupMenuItem(
                value: 'xlsx',
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.table_chart, color: Colors.green, size: 18),
                    SizedBox(width: 8),
                    Text('Excel (XLSX)'),
                  ],
                ),
              ),
              PopupMenuItem(
                value: 'csv',
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Icon(Icons.grid_on, color: Colors.blue, size: 18),
                    SizedBox(width: 8),
                    Text('CSV'),
                  ],
                ),
              ),
            ],
          ),
        ],
        bottom: TabBar(
          controller: _tabController,
          tabs: const [
            Tab(text: 'Laba Rugi (P&L)'),
            Tab(text: 'Arus Kas'),
            Tab(text: 'Beban Operasional'),
            Tab(text: 'Neraca Keuangan'),
          ],
        ),
      ),
      body: AnimatedBuilder(
        animation: widget.financeService,
        builder: (context, _) {
          if (widget.financeService.isLoading &&
              widget.financeService.profitLoss == null &&
              widget.financeService.cashFlow == null &&
              widget.financeService.balanceSheet == null &&
              widget.financeService.expenses.isEmpty) {
            return const Center(child: CircularProgressIndicator());
          }

          return TabBarView(
            controller: _tabController,
            children: [
              _buildProfitLossTab(),
              _buildCashFlowTab(),
              _buildExpensesTab(),
              _buildBalanceSheetTab(),
            ],
          );
        },
      ),
      floatingActionButton: _tabController.index == 2
          ? FloatingActionButton.extended(
              key: const Key('add_expense_fab'),
              onPressed: () {
                showDialog(
                  context: context,
                  builder: (ctx) => ExpenseFormDialog(
                    financeService: widget.financeService,
                    outletId: widget.outletId,
                    onSuccess: _loadData,
                  ),
                );
              },
              icon: const Icon(Icons.add),
              label: const Text('Catat Pengeluaran'),
            )
          : null,
    );
  }

  Widget _buildPeriodFilter() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
      child: Row(
        children: [
          const Text('Periode: ', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 13)),
          const SizedBox(width: 8),
          ChoiceChip(
            label: const Text('Hari Ini'),
            selected: _selectedPeriod == 'today',
            onSelected: (_) => _onPeriodChanged('today'),
          ),
          const SizedBox(width: 8),
          ChoiceChip(
            label: const Text('Minggu Ini'),
            selected: _selectedPeriod == 'this_week',
            onSelected: (_) => _onPeriodChanged('this_week'),
          ),
          const SizedBox(width: 8),
          ChoiceChip(
            label: const Text('Bulan Ini'),
            selected: _selectedPeriod == 'this_month',
            onSelected: (_) => _onPeriodChanged('this_month'),
          ),
        ],
      ),
    );
  }

  Widget _buildProfitLossTab() {
    final pnl = widget.financeService.profitLoss;

    return RefreshIndicator(
      onRefresh: _loadData,
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildPeriodFilter(),
            if (pnl == null)
              const Padding(
                padding: EdgeInsets.all(32),
                child: Center(child: Text('Data Laba Rugi belum tersedia.')),
              )
            else ...[
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Card(
                  elevation: 2,
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      children: [
                        _metricRow('Pendapatan Bersih (Net Revenue)', pnl.revenueMinor, Colors.green),
                        const Divider(),
                        _metricRow('Harga Pokok Penjualan (HPP)', -pnl.cogsMinor, Colors.deepOrange),
                        const Divider(),
                        _metricRow('Laba Kotor (Gross Profit)', pnl.grossProfitMinor, Colors.teal, isBold: true),
                        Padding(
                          padding: const EdgeInsets.only(top: 2, bottom: 4),
                          child: Align(
                            alignment: Alignment.centerRight,
                            child: Text(
                              'Margin Kotor: ${pnl.grossMarginPct.toStringAsFixed(1)}%',
                              style: TextStyle(fontSize: 12, color: Colors.teal.shade700, fontWeight: FontWeight.bold),
                            ),
                          ),
                        ),
                        const Divider(thickness: 2),
                        _metricRow('Beban Operasional (Expense)', -pnl.expenseMinor, Colors.redAccent),
                        const Divider(thickness: 2),
                        _metricRow('Laba Bersih (Net Profit)', pnl.netProfitMinor, Colors.blueAccent, isBold: true, isHighlight: true),
                        Padding(
                          padding: const EdgeInsets.only(top: 2, bottom: 4),
                          child: Align(
                            alignment: Alignment.centerRight,
                            child: Text(
                              'Margin Bersih: ${pnl.netMarginPct.toStringAsFixed(1)}%',
                              style: TextStyle(fontSize: 12, color: Colors.blueAccent.shade700, fontWeight: FontWeight.bold),
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
              ),
              if (pnl.comparison != null) ...[
                const SizedBox(height: 12),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Card(
                    color: Colors.blue.shade50,
                    child: Padding(
                      padding: const EdgeInsets.all(12),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.spaceAround,
                        children: [
                          Column(
                            children: [
                              const Text('Pertumbuhan Omzet', style: TextStyle(fontSize: 12)),
                              Text(
                                '${pnl.comparison!.revenueGrowthPct >= 0 ? '+' : ''}${pnl.comparison!.revenueGrowthPct.toStringAsFixed(1)}%',
                                style: TextStyle(
                                  fontWeight: FontWeight.bold,
                                  color: pnl.comparison!.revenueGrowthPct >= 0 ? Colors.green.shade700 : Colors.red.shade700,
                                ),
                              ),
                            ],
                          ),
                          Column(
                            children: [
                              const Text('Pertumbuhan Laba', style: TextStyle(fontSize: 12)),
                              Text(
                                '${pnl.comparison!.netProfitGrowthPct >= 0 ? '+' : ''}${pnl.comparison!.netProfitGrowthPct.toStringAsFixed(1)}%',
                                style: TextStyle(
                                  fontWeight: FontWeight.bold,
                                  color: pnl.comparison!.netProfitGrowthPct >= 0 ? Colors.green.shade700 : Colors.red.shade700,
                                ),
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                  ),
                ),
              ],
              const SizedBox(height: 16),
              if (pnl.categories.isNotEmpty) ...[
                const Padding(
                  padding: EdgeInsets.symmetric(horizontal: 20),
                  child: Text('Rincian Beban Operasional', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                ),
                const SizedBox(height: 8),
                Padding(
                  padding: const EdgeInsets.symmetric(horizontal: 16),
                  child: Card(
                    child: Column(
                      children: pnl.categories.entries.map((e) {
                        return ListTile(
                          title: Text(_formatCategory(e.key)),
                          trailing: Text(
                            ProfitLossReportModel.formatCurrency(e.value),
                            style: const TextStyle(fontWeight: FontWeight.bold),
                          ),
                        );
                      }).toList(),
                    ),
                  ),
                ),
              ],
            ],
            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }

  Widget _buildCashFlowTab() {
    final cf = widget.financeService.cashFlow;

    return RefreshIndicator(
      onRefresh: _loadData,
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _buildPeriodFilter(),
            Container(
              margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: Colors.amber.shade50,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: Colors.amber.shade300),
              ),
              child: const Row(
                children: [
                  Icon(Icons.info_outline, color: Colors.orange, size: 20),
                  SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      'Arus kas hanya mencatat transaksi kas fisik (uang tunai di outlet). Pembayaran digital (QRIS/Transfer) tidak termasuk kas tunai.',
                      style: TextStyle(fontSize: 12),
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 8),
            if (cf == null)
              const Padding(
                padding: EdgeInsets.all(32),
                child: Center(child: Text('Data Arus Kas belum tersedia.')),
              )
            else ...[
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Card(
                  elevation: 2,
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      children: [
                        _metricRow('Saldo Awal Kas', cf.openingCashMinor, Colors.blueGrey),
                        const Divider(),
                        _metricRow('Uang Masuk (Cash In)', cf.cashInMinor, Colors.green),
                        const Divider(),
                        _metricRow('Uang Keluar (Cash Out)', -cf.cashOutMinor, Colors.red),
                        const Divider(thickness: 2),
                        _metricRow('Arus Kas Bersih (Net Cashflow)', cf.netCashflowMinor, Colors.blue, isBold: true),
                        const Divider(thickness: 2),
                        _metricRow('Saldo Akhir Kas', cf.closingCashMinor, Colors.teal, isBold: true, isHighlight: true),
                      ],
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 16),
              const Padding(
                padding: EdgeInsets.symmetric(horizontal: 20),
                child: Text('Rincian Arus Kas Fisik', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              ),
              const SizedBox(height: 8),
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Card(
                  child: Column(
                    children: [
                      ListTile(
                        leading: const Icon(Icons.arrow_downward, color: Colors.green),
                        title: const Text('Penjualan Tunai'),
                        trailing: Text(CashFlowReportModel.formatCurrency(cf.cashSalesMinor)),
                      ),
                      ListTile(
                        leading: const Icon(Icons.arrow_upward, color: Colors.red),
                        title: const Text('Pengeluaran Kas Operasional'),
                        trailing: Text(CashFlowReportModel.formatCurrency(cf.cashExpensesMinor)),
                      ),
                      if (cf.cashRefundsMinor > 0)
                        ListTile(
                          leading: const Icon(Icons.undo, color: Colors.orange),
                          title: const Text('Pengembalian Dana Tunai (Refund)'),
                          trailing: Text(CashFlowReportModel.formatCurrency(cf.cashRefundsMinor)),
                        ),
                    ],
                  ),
                ),
              ),
            ],
            const SizedBox(height: 24),
          ],
        ),
      ),
    );
  }

  Widget _buildExpensesTab() {
    final all = widget.financeService.expenses;
    final filtered = all.where((e) {
      if (_expenseFilter == 'posted') return e.isPosted;
      if (_expenseFilter == 'reversed') return e.isReversed;
      return true;
    }).toList();

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            children: [
              ChoiceChip(
                label: const Text('Semua'),
                selected: _expenseFilter == 'all',
                onSelected: (_) => setState(() => _expenseFilter = 'all'),
              ),
              const SizedBox(width: 8),
              ChoiceChip(
                label: const Text('Terposting'),
                selected: _expenseFilter == 'posted',
                onSelected: (_) => setState(() => _expenseFilter = 'posted'),
              ),
              const SizedBox(width: 8),
              ChoiceChip(
                label: const Text('Dibatalkan'),
                selected: _expenseFilter == 'reversed',
                onSelected: (_) => setState(() => _expenseFilter = 'reversed'),
              ),
            ],
          ),
        ),
        Expanded(
          child: RefreshIndicator(
            onRefresh: _loadData,
            child: filtered.isEmpty
                ? const Center(
                    child: Text('Belum ada data pengeluaran.'),
                  )
                : ListView.builder(
                    itemCount: filtered.length,
                    itemBuilder: (context, idx) {
                      final exp = filtered[idx];
                      return Card(
                        margin: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                        child: ListTile(
                          leading: CircleAvatar(
                            backgroundColor: exp.isPosted ? Colors.blue.shade50 : Colors.red.shade50,
                            child: Icon(
                              exp.paymentMethod == 'cash' ? Icons.money : Icons.account_balance,
                              color: exp.isPosted ? Colors.blue : Colors.red,
                            ),
                          ),
                          title: Text(exp.categoryLabel, style: const TextStyle(fontWeight: FontWeight.bold)),
                          subtitle: Text(
                            '${exp.expenseDate} • ${exp.paymentMethod == 'cash' ? 'Tunai' : 'Bank'}${exp.description != null ? ' • ${exp.description}' : ''}',
                            maxLines: 1,
                            overflow: TextOverflow.ellipsis,
                          ),
                          trailing: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            crossAxisAlignment: CrossAxisAlignment.end,
                            children: [
                              Text(
                                exp.formattedAmount,
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                              ),
                              Text(
                                exp.statusLabel,
                                style: TextStyle(
                                  fontSize: 11,
                                  color: exp.isPosted
                                      ? Colors.green
                                      : exp.isReversed
                                          ? Colors.red
                                          : Colors.orange,
                                ),
                              ),
                            ],
                          ),
                          onTap: () {
                            showModalBottomSheet(
                              context: context,
                              builder: (ctx) => ExpenseDetailSheet(
                                expense: exp,
                                financeService: widget.financeService,
                                onReversed: _loadData,
                              ),
                            );
                          },
                        ),
                      );
                    },
                  ),
          ),
        ),
      ],
    );
  }

  Widget _buildBalanceSheetTab() {
    final bs = widget.financeService.balanceSheet;

    return RefreshIndicator(
      onRefresh: _loadData,
      child: SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            if (bs == null)
              const Padding(
                padding: EdgeInsets.all(32),
                child: Center(child: Text('Data Neraca belum tersedia.')),
              )
            else ...[
              // Balance Invariant Status Banner
              Padding(
                padding: const EdgeInsets.all(16),
                child: Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: bs.isBalanced ? Colors.green.shade50 : Colors.red.shade50,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(
                      color: bs.isBalanced ? Colors.green.shade300 : Colors.red.shade300,
                    ),
                  ),
                  child: Row(
                    children: [
                      Icon(
                        bs.isBalanced ? Icons.check_circle : Icons.warning,
                        color: bs.isBalanced ? Colors.green : Colors.red,
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          bs.isBalanced
                              ? 'Neraca Seimbang (Aset = Kewajiban + Ekuitas)'
                              : 'Terdapat Selisih Neraca: ${formatIdrCurrency(bs.discrepancyMinor)}',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            color: bs.isBalanced ? Colors.green.shade800 : Colors.red.shade800,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
              ),

              // 1. Assets Card
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'ASET (AKTIVA)',
                          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.teal),
                        ),
                        const SizedBox(height: 8),
                        _metricRow('Kas Tunai (1110)', bs.cashMinor, Colors.black87),
                        _metricRow('Kliring Bank / Non-Tunai (1120)', bs.clearingMinor, Colors.black87),
                        _metricRow('Persediaan Barang (1200)', bs.inventoryMinor, Colors.black87),
                        const Divider(thickness: 2),
                        _metricRow('Total Aset', bs.totalAssetsMinor, Colors.teal, isBold: true),
                      ],
                    ),
                  ),
                ),
              ),

              const SizedBox(height: 12),

              // 2. Liabilities Card
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'KEWAJIBAN (PASIVA)',
                          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.deepOrange),
                        ),
                        const SizedBox(height: 8),
                        _metricRow('Utang Pajak PB1 (2110)', bs.taxPayableMinor, Colors.black87),
                        const Divider(thickness: 2),
                        _metricRow('Total Kewajiban', bs.totalLiabilitiesMinor, Colors.deepOrange, isBold: true),
                      ],
                    ),
                  ),
                ),
              ),

              const SizedBox(height: 12),

              // 3. Equity Card
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Card(
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'EKUITAS (MODAL)',
                          style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16, color: Colors.indigo),
                        ),
                        const SizedBox(height: 8),
                        _metricRow('Modal Pemilik (3100)', bs.ownerEquityMinor, Colors.black87),
                        _metricRow('Laba Ditahan / Kumulatif', bs.retainedEarningsMinor, Colors.black87),
                        const Divider(thickness: 2),
                        _metricRow('Total Ekuitas', bs.totalEquityMinor, Colors.indigo, isBold: true),
                      ],
                    ),
                  ),
                ),
              ),

              const SizedBox(height: 12),

              // Total Liabilities + Equity Summary
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16),
                child: Card(
                  color: Colors.grey.shade100,
                  child: Padding(
                    padding: const EdgeInsets.all(16),
                    child: _metricRow(
                      'Total Kewajiban & Ekuitas',
                      bs.totalLiabilitiesAndEquityMinor,
                      Colors.black87,
                      isBold: true,
                      isHighlight: true,
                    ),
                  ),
                ),
              ),
              const SizedBox(height: 24),
            ],
          ],
        ),
      ),
    );
  }

  Widget _metricRow(String title, int amount, Color color, {bool isBold = false, bool isHighlight = false}) {
    final style = TextStyle(
      fontSize: isHighlight ? 16 : 14,
      fontWeight: isBold ? FontWeight.bold : FontWeight.normal,
    );

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(title, style: style),
          Text(
            ProfitLossReportModel.formatCurrency(amount),
            style: style.copyWith(color: color, fontWeight: FontWeight.bold),
          ),
        ],
      ),
    );
  }

  String _formatCategory(String key) {
    switch (key) {
      case 'utilities':
        return 'Utilitas & Listrik';
      case 'supplies':
        return 'Bahan & Perlengkapan';
      case 'rent':
        return 'Sewa Tempat';
      case 'salary':
        return 'Gaji & Upah';
      case 'maintenance':
        return 'Pemeliharaan';
      default:
        return 'Lain-lain';
    }
  }
}
