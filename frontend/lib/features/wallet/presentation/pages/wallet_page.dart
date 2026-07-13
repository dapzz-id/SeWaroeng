// Neura Cakrawira Solusi | SeWaroeng
// Wallet feature - Presentation Layer
import 'package:flutter/material.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../shared/widgets/glass_container.dart';

class WalletPage extends StatelessWidget {
  const WalletPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('SeWaroeng Wallet'),
        backgroundColor: AppColors.surface,
      ),
      body: ListView(
        padding: const EdgeInsets.all(16.0),
        children: [
          // Balance Card
          GlassContainer(
            padding: const EdgeInsets.all(24.0),
            color: AppColors.primary.withOpacity(0.1),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text('Saldo Anda', style: Theme.of(context).textTheme.titleMedium),
                const SizedBox(height: 8),
                Text(
                  'Rp 150.000',
                  style: Theme.of(context).textTheme.displaySmall?.copyWith(color: AppColors.primary),
                ),
                const SizedBox(height: 24),
                Row(
                  children: [
                    Expanded(
                      child: ElevatedButton.icon(
                        onPressed: () {},
                        icon: const Icon(Icons.add_circle_outline),
                        label: const Text('Top Up'),
                      ),
                    ),
                    const SizedBox(width: 16),
                    Expanded(
                      child: OutlinedButton.icon(
                        onPressed: () {},
                        icon: const Icon(Icons.history),
                        label: const Text('Riwayat'),
                      ),
                    ),
                  ],
                ),
              ],
            ),
          ),
          
          const SizedBox(height: 24),
          Text('Transaksi Terakhir', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 12),
          
          // Transaction List
          ListView.builder(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: 5,
            itemBuilder: (context, index) {
              final isCredit = index % 2 == 0;
              return ListTile(
                contentPadding: EdgeInsets.zero,
                leading: CircleAvatar(
                  backgroundColor: isCredit ? AppColors.success.withOpacity(0.2) : AppColors.error.withOpacity(0.2),
                  child: Icon(
                    isCredit ? Icons.arrow_downward : Icons.arrow_upward,
                    color: isCredit ? AppColors.success : AppColors.error,
                  ),
                ),
                title: Text(isCredit ? 'Top Up Saldo' : 'Pembayaran Pesanan'),
                subtitle: const Text('15 Okt 2023, 14:30'),
                trailing: Text(
                  '${isCredit ? '+' : '-'} Rp 50.000',
                  style: TextStyle(
                    color: isCredit ? AppColors.success : AppColors.error,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              );
            },
          ),
        ],
      ),
    );
  }
}
