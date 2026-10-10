import 'package:flutter/material.dart';
import 'package:frontend/core/subscription/subscription_models.dart';
import 'package:frontend/core/subscription/subscription_service.dart';

class SubscriptionOverviewScreen extends StatefulWidget {
  final SubscriptionService subscriptionService;

  const SubscriptionOverviewScreen({
    super.key,
    required this.subscriptionService,
  });

  @override
  State<SubscriptionOverviewScreen> createState() => _SubscriptionOverviewScreenState();
}

class _SubscriptionOverviewScreenState extends State<SubscriptionOverviewScreen> {
  @override
  void initState() {
    super.initState();
    widget.subscriptionService.loadSubscriptionData();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.subscriptionService,
      builder: (context, _) {
        final service = widget.subscriptionService;

        return Scaffold(
          appBar: AppBar(
            title: const Text('Langganan & Kuota'),
            actions: [
              IconButton(
                icon: const Icon(Icons.refresh),
                tooltip: 'Perbarui Data',
                onPressed: () => service.loadSubscriptionData(),
              ),
            ],
          ),
          body: _buildBody(service),
        );
      },
    );
  }

  Widget _buildBody(SubscriptionService service) {
    if (service.isLoading && service.subscription == null) {
      return const Center(child: CircularProgressIndicator());
    }

    if (service.errorMessage != null && service.subscription == null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24.0),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              const Icon(Icons.error_outline, size: 48, color: Colors.red),
              const SizedBox(height: 16),
              Text(
                service.errorMessage!,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 16),
              ),
              const SizedBox(height: 16),
              ElevatedButton(
                onPressed: () => service.loadSubscriptionData(),
                child: const Text('Coba Lagi'),
              ),
            ],
          ),
        ),
      );
    }

    final sub = service.subscription;
    if (sub == null) {
      return const Center(child: Text('Tidak ada data langganan.'));
    }

    final usage = service.usage;
    final entitlements = service.entitlements;

    return RefreshIndicator(
      onRefresh: () => service.loadSubscriptionData(),
      child: ListView(
        padding: const EdgeInsets.all(16.0),
        children: [
          _buildPlanCard(sub),
          const SizedBox(height: 16),
          if (usage != null) _buildUsageSection(usage),
          const SizedBox(height: 16),
          if (entitlements != null) _buildEntitlementsSection(entitlements),
          const SizedBox(height: 24),
          if (sub.status == 'active' || sub.status == 'trialing')
            _buildCancelButton(service),
        ],
      ),
    );
  }

  Widget _buildPlanCard(TenantSubscription sub) {
    final statusColor = switch (sub.status) {
      'active' => Colors.green,
      'trialing' => Colors.amber.shade800,
      'cancelled' => Colors.orange,
      _ => Colors.red,
    };

    final statusText = switch (sub.status) {
      'active' => 'Aktif',
      'trialing' => 'Masa Uji Coba (Trial)',
      'cancelled' => 'Dibatalkan',
      _ => 'Kadaluarsa',
    };

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(20.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Expanded(
                  child: Text(
                    sub.plan.name,
                    style: const TextStyle(fontSize: 22, fontWeight: FontWeight.bold),
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusColor.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(20),
                    border: Border.all(color: statusColor, width: 1),
                  ),
                  child: Text(
                    statusText,
                    style: TextStyle(color: statusColor, fontWeight: FontWeight.w600, fontSize: 13),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            if (sub.plan.description != null)
              Text(
                sub.plan.description!,
                style: TextStyle(color: Colors.grey.shade700, fontSize: 14),
              ),
            const Divider(height: 24),
            if (sub.isTrial)
              Container(
                margin: const EdgeInsets.only(bottom: 12),
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: Colors.amber.shade50,
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: Colors.amber.shade300),
                ),
                child: Row(
                  children: [
                    Icon(Icons.hourglass_top, color: Colors.amber.shade900, size: 20),
                    const SizedBox(width: 8),
                    Expanded(
                      child: Text(
                        'Trial aktif: ${sub.trialRemainingDays} hari tersisa',
                        style: TextStyle(
                          color: Colors.amber.shade900,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            _buildInfoRow('Periode Aktif', '${_formatDate(sub.currentPeriodStart)} - ${_formatDate(sub.currentPeriodEnd)}'),
            if (sub.cancelledAt != null)
              _buildInfoRow('Akses Berakhir', _formatDate(sub.currentPeriodEnd)),
          ],
        ),
      ),
    );
  }

  Widget _buildInfoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: TextStyle(color: Colors.grey.shade600, fontSize: 13)),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 13)),
        ],
      ),
    );
  }

  Widget _buildUsageSection(SubscriptionUsage usage) {
    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Penggunaan Kuota',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 12),
            _buildQuotaItem('Outlet / Cabang', usage.maxOutlets),
            _buildQuotaItem('Pengguna / Staf', usage.maxUsers),
            _buildQuotaItem('Katalog Produk', usage.maxProducts),
            _buildQuotaItem('Transaksi Bulanan', usage.maxMonthlyTransactions),
          ],
        ),
      ),
    );
  }

  Widget _buildQuotaItem(String label, QuotaDimensionUsage item) {
    final limitStr = item.isUnlimited ? 'Tak Terbatas' : '${item.limit}';
    final progress = item.isUnlimited || item.limit == 0
        ? 0.0
        : (item.current / item.limit!).clamp(0.0, 1.0);

    final isNearLimit = !item.isUnlimited && item.limit != null && item.current >= item.limit!;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 8),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(label, style: const TextStyle(fontWeight: FontWeight.w500)),
              Text(
                '${item.current} / $limitStr',
                style: TextStyle(
                  fontWeight: FontWeight.w600,
                  color: isNearLimit ? Colors.red.shade700 : Colors.black87,
                ),
              ),
            ],
          ),
          const SizedBox(height: 6),
          LinearProgressIndicator(
            value: progress,
            backgroundColor: Colors.grey.shade200,
            valueColor: AlwaysStoppedAnimation<Color>(
              isNearLimit ? Colors.red : Theme.of(context).primaryColor,
            ),
            minHeight: 6,
          ),
        ],
      ),
    );
  }

  Widget _buildEntitlementsSection(SubscriptionEntitlements entitlements) {
    final featureNames = {
      'pos': 'Kasir POS Online & Offline',
      'inventory': 'Manajemen Stok & Inventaris',
      'customer_crm': 'CRM & Riwayat Pelanggan',
      'finance': 'Laporan Keuangan & Beban',
      'dashboard': 'Dashboard Operasional',
      'offline_pos': 'Offline-First POS',
      'multi_outlet': 'Manajemen Multi-Cabang',
      'advanced_reports': 'Analisis & Laporan Mendalam',
      'whatsapp': 'Notifikasi WhatsApp Resmi',
      'ai_copilot': 'SeWaroeng AI Copilot',
    };

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Fitur Paket',
              style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            ...featureNames.entries.map((entry) {
              final isEnabled = entitlements.can(entry.key);
              return ListTile(
                dense: true,
                contentPadding: EdgeInsets.zero,
                leading: Icon(
                  isEnabled ? Icons.check_circle : Icons.cancel_outlined,
                  color: isEnabled ? Colors.green : Colors.grey.shade400,
                  size: 20,
                ),
                title: Text(
                  entry.value,
                  style: TextStyle(
                    color: isEnabled ? Colors.black87 : Colors.grey.shade500,
                    fontWeight: isEnabled ? FontWeight.w500 : FontWeight.normal,
                  ),
                ),
                trailing: isEnabled
                    ? null
                    : Text(
                        'Fitur belum tersedia pada paket Anda.',
                        style: TextStyle(fontSize: 11, color: Colors.grey.shade500),
                      ),
              );
            }),
          ],
        ),
      ),
    );
  }

  Widget _buildCancelButton(SubscriptionService service) {
    return OutlinedButton.icon(
      style: OutlinedButton.styleFrom(
        foregroundColor: Colors.red,
        side: const BorderSide(color: Colors.red),
        padding: const EdgeInsets.symmetric(vertical: 12),
      ),
      icon: const Icon(Icons.cancel_outlined),
      label: const Text('Batalkan Langganan'),
      onPressed: () => _confirmCancel(service),
    );
  }

  void _confirmCancel(SubscriptionService service) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Konfirmasi Pembatalan'),
        content: const Text(
          'Apakah Anda yakin ingin membatalkan langganan? '
          'Akses fitur tetap dapat digunakan hingga akhir periode tagihan saat ini.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Kembali'),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red),
            onPressed: () async {
              Navigator.pop(ctx);
              final success = await service.cancelSubscription();
              if (mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: Text(
                      success
                          ? 'Langganan berhasil dibatalkan.'
                          : (service.errorMessage ?? 'Gagal membatalkan langganan.'),
                    ),
                  ),
                );
              }
            },
            child: const Text('Ya, Batalkan', style: TextStyle(color: Colors.white)),
          ),
        ],
      ),
    );
  }

  String _formatDate(DateTime dt) {
    return '${dt.day.toString().padLeft(2, '0')}/${dt.month.toString().padLeft(2, '0')}/${dt.year}';
  }
}
