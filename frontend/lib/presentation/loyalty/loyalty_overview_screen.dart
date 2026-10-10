import 'package:flutter/material.dart';
import '../../core/loyalty/loyalty_models.dart';
import '../../core/loyalty/loyalty_service.dart';

class LoyaltyOverviewScreen extends StatefulWidget {
  final LoyaltyService loyaltyService;
  final String? customerId;
  final String? customerName;

  const LoyaltyOverviewScreen({
    super.key,
    required this.loyaltyService,
    this.customerId,
    this.customerName,
  });

  @override
  State<LoyaltyOverviewScreen> createState() => _LoyaltyOverviewScreenState();
}

class _LoyaltyOverviewScreenState extends State<LoyaltyOverviewScreen>
    with SingleTickerProviderStateMixin {
  late TabController _tabController;

  bool _isLoading = true;
  String? _errorMessage;

  LoyaltyProgramModel? _program;
  List<LoyaltyTierModel> _tiers = [];
  List<LoyaltyRewardModel> _rewards = [];
  CustomerLoyaltyAccountModel? _account;
  List<LoyaltyVoucherModel> _vouchers = [];
  List<LoyaltyLedgerItemModel> _ledgers = [];

  @override
  void initState() {
    super.initState();
    _tabController = TabController(length: 4, vsync: this);
    _loadAllData();
  }

  @override
  void dispose() {
    _tabController.dispose();
    super.dispose();
  }

  String _formatCurrency(int minor) {
    final rupiah = (minor / 100).round();
    return 'Rp ${_formatNumber(rupiah)}';
  }

  String _formatNumber(int number) {
    final isNegative = number < 0;
    final str = number.abs().toString();
    final buffer = StringBuffer();
    final offset = str.length % 3;
    for (int i = 0; i < str.length; i++) {
      if (i > 0 && (i - offset) % 3 == 0) {
        buffer.write('.');
      }
      buffer.write(str[i]);
    }
    return '${isNegative ? '-' : ''}${buffer.toString()}';
  }

  Future<void> _loadAllData() async {
    setState(() {
      _isLoading = true;
      _errorMessage = null;
    });

    try {
      final program = await widget.loyaltyService.getProgram();
      final tiers = await widget.loyaltyService.getTiers();
      final rewards = await widget.loyaltyService.getRewards();

      CustomerLoyaltyAccountModel? account;
      List<LoyaltyVoucherModel> vouchers = [];
      List<LoyaltyLedgerItemModel> ledgers = [];

      if (widget.customerId != null) {
        account = await widget.loyaltyService.getCustomerAccount(widget.customerId!);
        vouchers = await widget.loyaltyService.getCustomerVouchers(widget.customerId!);
        ledgers = await widget.loyaltyService.getCustomerLedger(widget.customerId!);
      }

      if (mounted) {
        setState(() {
          _program = program;
          _tiers = tiers;
          _rewards = rewards;
          _account = account;
          _vouchers = vouchers;
          _ledgers = ledgers;
          _isLoading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _errorMessage = 'Gagal memuat data loyalitas: ${e.toString()}';
          _isLoading = false;
        });
      }
    }
  }

  Future<void> _redeemReward(LoyaltyRewardModel reward) async {
    if (widget.customerId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Pilih pelanggan terlebih dahulu untuk menukarkan reward.')),
      );
      return;
    }

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Konfirmasi Penukaran Poin'),
        content: Text(
          'Tukarkan ${_formatNumber(reward.pointsCost)} poin untuk "${reward.name}"?',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Batal'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, true),
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF1B5E20),
              foregroundColor: Colors.white,
            ),
            child: const Text('Tukarkan'),
          ),
        ],
      ),
    );

    if (confirmed != true) return;

    try {
      final voucher = await widget.loyaltyService.redeemReward(
        widget.customerId!,
        reward.id,
      );

      if (mounted) {
        if (voucher != null) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text('Reward berhasil ditukar! Kode voucher: ${voucher.code}'),
              backgroundColor: const Color(0xFF2E7D32),
            ),
          );
          _loadAllData();
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Gagal menukarkan reward. Pastikan poin mencukupi.'),
              backgroundColor: Colors.red,
            ),
          );
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text('Terjadi kesalahan: ${e.toString()}'),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }

  Future<void> _showAdjustPointsDialog() async {
    if (widget.customerId == null) return;

    final pointsCtrl = TextEditingController();
    final reasonCtrl = TextEditingController();

    await showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Koreksi Poin Manual'),
        content: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: pointsCtrl,
                keyboardType: const TextInputType.numberWithOptions(signed: true),
                decoration: const InputDecoration(
                  labelText: 'Jumlah Poin (+/-)',
                  hintText: 'Contoh: 50 atau -20',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: reasonCtrl,
                decoration: const InputDecoration(
                  labelText: 'Alasan Penyesuaian',
                  hintText: 'Contoh: Koreksi komplain layanan',
                ),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Batal'),
          ),
          ElevatedButton(
            onPressed: () async {
              final pts = int.tryParse(pointsCtrl.text.trim());
              final rsn = reasonCtrl.text.trim();
              if (pts == null || pts == 0 || rsn.isEmpty) {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Isi jumlah poin dan alasan dengan benar.')),
                );
                return;
              }

              Navigator.pop(ctx);
              final success = await widget.loyaltyService.adjustCustomerPoints(
                widget.customerId!,
                pts,
                rsn,
              );

              if (mounted) {
                if (success) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                      content: Text('Poin berhasil disesuaikan.'),
                      backgroundColor: Color(0xFF2E7D32),
                    ),
                  );
                  _loadAllData();
                } else {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(
                      content: Text('Gagal menyesuaikan poin.'),
                      backgroundColor: Colors.red,
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
    return Scaffold(
      appBar: AppBar(
        title: Text(widget.customerName != null
            ? 'Loyalitas: ${widget.customerName}'
            : 'Program Loyalitas'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadAllData,
            tooltip: 'Segarkan',
          ),
        ],
        bottom: TabBar(
          controller: _tabController,
          isScrollable: true,
          tabs: const [
            Tab(icon: Icon(Icons.card_membership), text: 'Akun & Tier'),
            Tab(icon: Icon(Icons.redeem), text: 'Katalog Reward'),
            Tab(icon: Icon(Icons.confirmation_number), text: 'Voucher'),
            Tab(icon: Icon(Icons.receipt_long), text: 'Buku Besar'),
          ],
        ),
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _errorMessage != null
              ? Center(
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: [
                      const Icon(Icons.error_outline, size: 48, color: Colors.red),
                      const SizedBox(height: 12),
                      Text(_errorMessage!, textAlign: TextAlign.center),
                      const SizedBox(height: 16),
                      ElevatedButton(
                        onPressed: _loadAllData,
                        child: const Text('Coba Lagi'),
                      ),
                    ],
                  ),
                )
              : TabBarView(
                  controller: _tabController,
                  children: [
                    _buildAccountAndTierTab(),
                    _buildRewardsTab(),
                    _buildVouchersTab(),
                    _buildLedgerTab(),
                  ],
                ),
    );
  }

  Widget _buildAccountAndTierTab() {
    return ListView(
      padding: const EdgeInsets.all(16),
      children: [
        // Loyalty Card Widget
        Card(
          elevation: 3,
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
          color: const Color(0xFF0F2027),
          child: Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(16),
              gradient: const LinearGradient(
                colors: [Color(0xFF2C5364), Color(0xFF203A43), Color(0xFF0F2027)],
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
              ),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      _program?.name ?? 'Program Loyalitas',
                      style: const TextStyle(
                        color: Colors.white70,
                        fontSize: 14,
                        fontWeight: FontWeight.w500,
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                      decoration: BoxDecoration(
                        color: const Color(0xFFFFD700).withValues(alpha: 0.2),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: const Color(0xFFFFD700)),
                      ),
                      child: Text(
                        _account?.currentTier?.name ?? 'Bronze',
                        style: const TextStyle(
                          color: Color(0xFFFFD700),
                          fontWeight: FontWeight.bold,
                          fontSize: 12,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 24),
                const Text(
                  'Poin Anda',
                  style: TextStyle(color: Colors.white70, fontSize: 13),
                ),
                const SizedBox(height: 4),
                Text(
                  '${_formatNumber(_account?.currentBalance ?? 0)} poin',
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 32,
                    fontWeight: FontWeight.bold,
                    letterSpacing: 0.5,
                  ),
                ),
                const SizedBox(height: 16),
                if (_account?.nextTier != null) ...[
                  Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      Text(
                        'Menuju ${_account!.nextTier!['name']}:',
                        style: const TextStyle(color: Colors.white70, fontSize: 12),
                      ),
                      Text(
                        '${_formatNumber((_account!.nextTier!['points_needed'] as num?)?.toInt() ?? 0)} poin lagi',
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w600,
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 6),
                  ClipRRect(
                    borderRadius: BorderRadius.circular(4),
                    child: LinearProgressIndicator(
                      value: _calculateTierProgress(),
                      backgroundColor: Colors.white24,
                      valueColor: const AlwaysStoppedAnimation<Color>(Color(0xFF4CAF50)),
                      minHeight: 6,
                    ),
                  ),
                ],
                const SizedBox(height: 16),
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      'Total Didapat: ${_formatNumber(_account?.lifetimeEarned ?? 0)} poin',
                      style: const TextStyle(color: Colors.white60, fontSize: 11),
                    ),
                    Text(
                      'Total Ditukar: ${_formatNumber(_account?.lifetimeRedeemed ?? 0)} poin',
                      style: const TextStyle(color: Colors.white60, fontSize: 11),
                    ),
                  ],
                ),
              ],
            ),
          ),
        ),

        const SizedBox(height: 20),

        if (widget.customerId != null)
          Align(
            alignment: Alignment.centerRight,
            child: OutlinedButton.icon(
              onPressed: _showAdjustPointsDialog,
              icon: const Icon(Icons.tune),
              label: const Text('Koreksi Poin Manual'),
            ),
          ),

        const SizedBox(height: 16),
        const Text(
          'Daftar Tingkat (Tiers)',
          style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
        ),
        const SizedBox(height: 10),
        ..._tiers.map((tier) => Card(
              margin: const EdgeInsets.only(bottom: 8),
              child: ListTile(
                leading: CircleAvatar(
                  backgroundColor: _getTierColor(tier.name),
                  child: Text(
                    '#${tier.rank}',
                    style: const TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
                  ),
                ),
                title: Text(
                  tier.name,
                  style: const TextStyle(fontWeight: FontWeight.bold),
                ),
                subtitle: Text(
                  'Minimal ${_formatNumber(tier.minPointsEarned)} poin • Multiplier: ${tier.multiplierFormatted}',
                ),
                trailing: _account?.currentTier?.id == tier.id
                    ? const Chip(
                        label: Text('Aktif', style: TextStyle(fontSize: 11)),
                        backgroundColor: Color(0xFFE8F5E9),
                      )
                    : null,
              ),
            )),
      ],
    );
  }

  Widget _buildRewardsTab() {
    if (_rewards.isEmpty) {
      return const Center(
        child: Text('Belum ada reward yang tersedia saat ini.'),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _rewards.length,
      itemBuilder: (context, index) {
        final reward = _rewards[index];
        final canRedeem = (_account?.currentBalance ?? 0) >= reward.pointsCost;

        return Card(
          margin: const EdgeInsets.only(bottom: 12),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: const Color(0xFFE8F5E9),
                    borderRadius: BorderRadius.circular(10),
                  ),
                  child: const Icon(Icons.card_giftcard, color: Color(0xFF2E7D32), size: 28),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        reward.name,
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                      if (reward.description != null) ...[
                        const SizedBox(height: 4),
                        Text(
                          reward.description!,
                          style: TextStyle(color: Colors.grey[600], fontSize: 13),
                        ),
                      ],
                      const SizedBox(height: 8),
                      Text(
                        reward.discountAmountMinor > 0
                            ? 'Nilai: ${_formatCurrency(reward.discountAmountMinor)}'
                            : 'Diskon: ${reward.discountPercent}%',
                        style: const TextStyle(
                          color: Color(0xFF2E7D32),
                          fontWeight: FontWeight.w600,
                          fontSize: 13,
                        ),
                      ),
                      if (reward.minPurchaseMinor > 0)
                        Text(
                          'Min. Belanja: ${_formatCurrency(reward.minPurchaseMinor)}',
                          style: TextStyle(color: Colors.grey[500], fontSize: 11),
                        ),
                    ],
                  ),
                ),
                const SizedBox(width: 10),
                Column(
                  crossAxisAlignment: CrossAxisAlignment.end,
                  children: [
                    Text(
                      '${_formatNumber(reward.pointsCost)} poin',
                      style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        color: Color(0xFFE65100),
                        fontSize: 14,
                      ),
                    ),
                    const SizedBox(height: 8),
                    ElevatedButton(
                      onPressed: canRedeem ? () => _redeemReward(reward) : null,
                      style: ElevatedButton.styleFrom(
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 8),
                        backgroundColor: const Color(0xFF2E7D32),
                        foregroundColor: Colors.white,
                      ),
                      child: const Text('Tukar', style: TextStyle(fontSize: 12)),
                    ),
                  ],
                ),
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildVouchersTab() {
    if (_vouchers.isEmpty) {
      return const Center(
        child: Text('Belum ada voucher aktif yang dimiliki.'),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _vouchers.length,
      itemBuilder: (context, index) {
        final voucher = _vouchers[index];

        return Card(
          margin: const EdgeInsets.only(bottom: 12),
          shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
          child: Padding(
            padding: const EdgeInsets.all(16),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      voucher.code,
                      style: const TextStyle(
                        fontWeight: FontWeight.bold,
                        fontSize: 16,
                        letterSpacing: 1,
                      ),
                    ),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                      decoration: BoxDecoration(
                        color: voucher.status == 'active'
                            ? const Color(0xFFE8F5E9)
                            : Colors.grey[200],
                        borderRadius: BorderRadius.circular(6),
                      ),
                      child: Text(
                        voucher.status == 'active' ? 'Aktif' : voucher.status,
                        style: TextStyle(
                          color: voucher.status == 'active'
                              ? const Color(0xFF2E7D32)
                              : Colors.grey[700],
                          fontSize: 11,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  voucher.rewardName,
                  style: const TextStyle(fontSize: 14, fontWeight: FontWeight.w500),
                ),
                const SizedBox(height: 4),
                Text(
                  voucher.discountAmountMinor > 0
                      ? 'Diskon ${_formatCurrency(voucher.discountAmountMinor)}'
                      : 'Diskon ${voucher.discountPercent}%',
                  style: const TextStyle(color: Color(0xFF2E7D32), fontWeight: FontWeight.bold),
                ),
                if (voucher.expiresAt != null) ...[
                  const SizedBox(height: 6),
                  Text(
                    'Berlaku hingga: ${voucher.expiresAt!.substring(0, 10)}',
                    style: TextStyle(color: Colors.grey[500], fontSize: 11),
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  Widget _buildLedgerTab() {
    if (_ledgers.isEmpty) {
      return const Center(
        child: Text('Belum ada mutasi buku besar poin.'),
      );
    }

    return ListView.builder(
      padding: const EdgeInsets.all(16),
      itemCount: _ledgers.length,
      itemBuilder: (context, index) {
        final item = _ledgers[index];
        final isPositive = item.points >= 0;

        return Card(
          margin: const EdgeInsets.only(bottom: 8),
          child: ListTile(
            leading: Icon(
              isPositive ? Icons.add_circle : Icons.remove_circle,
              color: isPositive ? const Color(0xFF2E7D32) : Colors.red,
            ),
            title: Text(
              item.notes ?? _formatLedgerType(item.type),
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 14),
            ),
            subtitle: Text(
              'Saldo: ${_formatNumber(item.balanceAfter)} poin • ${item.createdAt?.substring(0, 10) ?? ''}',
              style: TextStyle(color: Colors.grey[600], fontSize: 12),
            ),
            trailing: Text(
              '${isPositive ? '+' : ''}${_formatNumber(item.points)}',
              style: TextStyle(
                fontWeight: FontWeight.bold,
                fontSize: 15,
                color: isPositive ? const Color(0xFF2E7D32) : Colors.red,
              ),
            ),
          ),
        );
      },
    );
  }

  double _calculateTierProgress() {
    if (_account == null || _account!.nextTier == null) return 1.0;
    final needed = (_account!.nextTier!['points_needed'] as num?)?.toDouble() ?? 0.0;
    final earned = _account!.lifetimeEarned.toDouble();
    final target = earned + needed;
    if (target <= 0) return 1.0;
    return (earned / target).clamp(0.0, 1.0);
  }

  String _formatLedgerType(String type) {
    switch (type) {
      case 'purchase':
        return 'Transaksi Pembelian';
      case 'redemption':
        return 'Penukaran Reward';
      case 'adjustment':
        return 'Penyesuaian Manual';
      case 'campaign':
        return 'Promosi Kampanye';
      case 'referral':
        return 'Referral Teman';
      case 'birthday':
        return 'Hadiah Ulang Tahun';
      case 'expiration':
        return 'Poin Kadaluarsa';
      default:
        return 'Mutasi Poin';
    }
  }

  Color _getTierColor(String name) {
    switch (name.toLowerCase()) {
      case 'silver':
        return const Color(0xFF9E9E9E);
      case 'gold':
        return const Color(0xFFFFB300);
      case 'vip':
      case 'platinum':
        return const Color(0xFF7E57C2);
      default:
        return const Color(0xFF8D6E63);
    }
  }
}
