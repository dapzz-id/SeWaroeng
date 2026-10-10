import 'package:flutter/material.dart';
import '../../core/payment/payment_reconciliation_models.dart';
import '../../core/payment/payment_reconciliation_service.dart';

class PaymentReconciliationScreen extends StatefulWidget {
  final PaymentReconciliationService reconciliationService;
  final String? initialOutletId;
  final bool canResolve;

  const PaymentReconciliationScreen({
    super.key,
    required this.reconciliationService,
    this.initialOutletId,
    this.canResolve = true,
  });

  @override
  State<PaymentReconciliationScreen> createState() =>
      _PaymentReconciliationScreenState();
}

class _PaymentReconciliationScreenState
    extends State<PaymentReconciliationScreen> {
  String _selectedStatus = 'all';
  String? _selectedOutletId;

  final Map<String, String> _statusFilters = {
    'all': 'Semua',
    'matched': 'Cocok',
    'mismatched': 'Selisih',
    'unmatched': 'Belum Cocok',
    'resolved': 'Selesai',
  };

  @override
  void initState() {
    super.initState();
    _selectedOutletId = widget.initialOutletId;
    _loadData();
  }

  Future<void> _loadData() async {
    final status = _selectedStatus == 'all' ? null : _selectedStatus;
    await Future.wait([
      widget.reconciliationService.fetchReconciliations(
        outletId: _selectedOutletId,
        status: status,
      ),
      widget.reconciliationService.fetchSummary(
        outletId: _selectedOutletId,
      ),
    ]);
  }

  String _formatRupiah(int amountMinor) {
    final numberStr = amountMinor.toString();
    final buffer = StringBuffer();
    int count = 0;
    for (int i = numberStr.length - 1; i >= 0; i--) {
      buffer.write(numberStr[i]);
      count++;
      if (count % 3 == 0 && i != 0) {
        buffer.write('.');
      }
    }
    return 'Rp ${buffer.toString().split('').reversed.join('')}';
  }

  void _showResolutionDialog(PaymentReconciliationModel item) {
    final notesController = TextEditingController();
    final formKey = GlobalKey<FormState>();

    showDialog<void>(
      context: context,
      builder: (dialogCtx) => AlertDialog(
        title: const Text('Selesaikan Selisih'),
        content: Form(
          key: formKey,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                'Referensi: ${item.providerReference}',
                style: const TextStyle(fontWeight: FontWeight.bold),
              ),
              const SizedBox(height: 8),
              Text(
                'Nominal Gateway: ${_formatRupiah(item.providerAmountMinor)}',
              ),
              if (item.expectedAmountMinor != null)
                Text(
                  'Nominal Tercatat: ${_formatRupiah(item.expectedAmountMinor!)}',
                ),
              const SizedBox(height: 16),
              TextFormField(
                controller: notesController,
                maxLines: 3,
                decoration: const InputDecoration(
                  labelText: 'Alasan / Catatan Penyelesaian',
                  hintText: 'Contoh: Biaya admin promosi atau selisih diskon...',
                  border: OutlineInputBorder(),
                ),
                validator: (val) {
                  if (val == null || val.trim().length < 3) {
                    return 'Catatan minimal 3 karakter.';
                  }
                  return null;
                },
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(dialogCtx).pop(),
            child: const Text('Batal'),
          ),
          ElevatedButton(
            onPressed: () async {
              if (formKey.currentState?.validate() ?? false) {
                Navigator.of(dialogCtx).pop();
                final success = await widget.reconciliationService
                    .resolveDiscrepancy(item.id, notesController.text.trim());
                if (mounted && success) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                      content: Text('Selisih berhasil diselesaikan.'),
                      backgroundColor: Colors.green,
                    ),
                  );
                }
              }
            },
            child: const Text('Simpan'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.reconciliationService,
      builder: (context, _) {
        final isLoading = widget.reconciliationService.isLoading;
        final errorMessage = widget.reconciliationService.errorMessage;
        final items = widget.reconciliationService.items;
        final summary = widget.reconciliationService.summary;

        return Scaffold(
          appBar: AppBar(
            title: const Text('Rekonsiliasi Pembayaran'),
            actions: [
              IconButton(
                icon: const Icon(Icons.refresh),
                onPressed: isLoading ? null : _loadData,
                tooltip: 'Muat Ulang',
              ),
            ],
          ),
          body: RefreshIndicator(
            onRefresh: _loadData,
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                if (errorMessage != null)
                  Container(
                    margin: const EdgeInsets.only(bottom: 16),
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.red.shade50,
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: Colors.red.shade200),
                    ),
                    child: Row(
                      children: [
                        Icon(Icons.error_outline, color: Colors.red.shade700),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            errorMessage,
                            style: TextStyle(color: Colors.red.shade900),
                          ),
                        ),
                      ],
                    ),
                  ),

                // Summary Dashboard Cards
                _buildSummaryGrid(summary),

                const SizedBox(height: 16),

                // Filter Chips
                SingleChildScrollView(
                  scrollDirection: Axis.horizontal,
                  child: Row(
                    children: _statusFilters.entries.map((entry) {
                      final isSelected = _selectedStatus == entry.key;
                      return Padding(
                        padding: const EdgeInsets.only(right: 8),
                        child: FilterChip(
                          label: Text(entry.value),
                          selected: isSelected,
                          onSelected: (selected) {
                            if (selected) {
                              setState(() {
                                _selectedStatus = entry.key;
                              });
                              _loadData();
                            }
                          },
                        ),
                      );
                    }).toList(),
                  ),
                ),

                const SizedBox(height: 16),

                // Content List
                if (isLoading && items.isEmpty)
                  const Center(
                    child: Padding(
                      padding: EdgeInsets.all(32),
                      child: CircularProgressIndicator(),
                    ),
                  )
                else if (items.isEmpty)
                  _buildEmptyState()
                else
                  ...items.map((item) => _buildReconciliationCard(item)),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildSummaryGrid(ReconciliationSummaryModel summary) {
    return Column(
      children: [
        Row(
          children: [
            Expanded(
              child: _buildMetricCard(
                title: 'Tercocokkan',
                count: summary.matchedCount,
                color: Colors.green,
                icon: Icons.check_circle_outline,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: _buildMetricCard(
                title: 'Selisih',
                count: summary.mismatchedCount,
                color: Colors.red,
                icon: Icons.warning_amber_outlined,
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        Row(
          children: [
            Expanded(
              child: _buildMetricCard(
                title: 'Belum Cocok',
                count: summary.unmatchedCount,
                color: Colors.orange,
                icon: Icons.help_outline,
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: _buildMetricCard(
                title: 'Selesai',
                count: summary.resolvedCount,
                color: Colors.blue,
                icon: Icons.verified_outlined,
              ),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildMetricCard({
    required String title,
    required int count,
    required Color color,
    required IconData icon,
  }) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.08),
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: color.withValues(alpha: 0.2)),
      ),
      child: Row(
        children: [
          Icon(icon, color: color, size: 28),
          const SizedBox(width: 10),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  title,
                  style: TextStyle(
                    fontSize: 12,
                    fontWeight: FontWeight.w600,
                    color: color,
                  ),
                ),
                Text(
                  count.toString(),
                  style: const TextStyle(
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildReconciliationCard(PaymentReconciliationModel item) {
    Color badgeColor = Colors.grey;
    String badgeLabel = 'Belum Cocok';

    if (item.isMatched) {
      badgeColor = Colors.green;
      badgeLabel = 'Cocok';
    } else if (item.isMismatched) {
      badgeColor = Colors.red;
      badgeLabel = 'Selisih';
    } else if (item.isResolved) {
      badgeColor = Colors.blue;
      badgeLabel = 'Selesai';
    } else if (item.isNeedsReview) {
      badgeColor = Colors.amber.shade800;
      badgeLabel = 'Perlu Ditinjau';
    }

    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: Colors.blueGrey.shade50,
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    '${item.provider.toUpperCase()}${item.channel != null ? ' • ${item.channel!.toUpperCase()}' : ''}',
                    style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
                Container(
                  padding:
                      const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: badgeColor.withValues(alpha: 0.15),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    badgeLabel,
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                      color: badgeColor,
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Text(
              'Ref: ${item.providerReference}',
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
            ),
            const SizedBox(height: 6),
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Gateway',
                        style: TextStyle(fontSize: 11, color: Colors.grey)),
                    Text(
                      _formatRupiah(item.providerAmountMinor),
                      style: const TextStyle(
                          fontSize: 13, fontWeight: FontWeight.bold),
                    ),
                  ],
                ),
                if (item.expectedAmountMinor != null)
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text('Tercatat',
                          style: TextStyle(fontSize: 11, color: Colors.grey)),
                      Text(
                        _formatRupiah(item.expectedAmountMinor!),
                        style: const TextStyle(
                            fontSize: 13, fontWeight: FontWeight.bold),
                      ),
                    ],
                  ),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Text('Biaya Gateway',
                        style: TextStyle(fontSize: 11, color: Colors.grey)),
                    Text(
                      _formatRupiah(item.feeAmountMinor),
                      style: const TextStyle(
                          fontSize: 13, fontWeight: FontWeight.bold),
                    ),
                  ],
                ),
              ],
            ),
            if (item.mismatchReason != null) ...[
              const SizedBox(height: 10),
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: Colors.red.shade50,
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Row(
                  children: [
                    Icon(Icons.info_outline,
                        size: 16, color: Colors.red.shade700),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        'Penyebab: ${item.mismatchReason}',
                        style: TextStyle(
                            fontSize: 11, color: Colors.red.shade900),
                      ),
                    ),
                  ],
                ),
              ),
            ],
            if (item.resolutionNotes != null) ...[
              const SizedBox(height: 8),
              Text(
                'Catatan: ${item.resolutionNotes}',
                style: const TextStyle(fontSize: 11, fontStyle: FontStyle.italic),
              ),
            ],
            if (widget.canResolve && (item.isMismatched || item.isNeedsReview)) ...[
              const SizedBox(height: 10),
              Align(
                alignment: Alignment.centerRight,
                child: OutlinedButton.icon(
                  onPressed: () => _showResolutionDialog(item),
                  icon: const Icon(Icons.check, size: 16),
                  label: const Text('Selesaikan Selisih'),
                  style: OutlinedButton.styleFrom(
                    visualDensity: VisualDensity.compact,
                  ),
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildEmptyState() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(32),
        child: Column(
          children: [
            Icon(Icons.account_balance_wallet_outlined,
                size: 64, color: Colors.grey.shade400),
            const SizedBox(height: 16),
            const Text(
              'Tidak ada data rekonsiliasi.',
              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            Text(
              'Gunakan filter lain atau impor bukti transaksi gateway pembayaran.',
              textAlign: TextAlign.center,
              style: TextStyle(color: Colors.grey.shade600, fontSize: 13),
            ),
          ],
        ),
      ),
    );
  }
}
