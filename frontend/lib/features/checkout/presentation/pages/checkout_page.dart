// Neura Cakrawira Solusi | SeWaroeng
// Checkout feature - Presentation Layer
import 'package:flutter/material.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../shared/widgets/glass_container.dart';

class CheckoutPage extends StatelessWidget {
  const CheckoutPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Checkout'),
        backgroundColor: AppColors.surface,
      ),
      body: ListView(
        padding: const EdgeInsets.all(16.0),
        children: [
          // Shipping Address
          Text('Alamat Pengiriman', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          GlassContainer(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text('Rumah', style: Theme.of(context).textTheme.labelLarge),
                    const Text('Ubah', style: TextStyle(color: AppColors.primary)),
                  ],
                ),
                const SizedBox(height: 8),
                Text('Budi Santoso', style: Theme.of(context).textTheme.bodyMedium),
                Text('081234567890', style: Theme.of(context).textTheme.bodyMedium),
                Text('Jl. Jend. Sudirman No. 1, Jakarta', style: Theme.of(context).textTheme.bodyMedium),
              ],
            ),
          ),
          
          const SizedBox(height: 24),
          
          // Shipping Method
          Text('Metode Pengiriman', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          GlassContainer(
            padding: const EdgeInsets.all(16.0),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Kurir Toko', style: Theme.of(context).textTheme.labelLarge),
                    Text('Estimasi: Hari Ini', style: Theme.of(context).textTheme.bodySmall),
                  ],
                ),
                Text('Rp 10.000', style: Theme.of(context).textTheme.labelLarge),
              ],
            ),
          ),
          
          const SizedBox(height: 24),
          
          // Payment Method
          Text('Metode Pembayaran', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          GlassContainer(
            padding: const EdgeInsets.all(16.0),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    const Icon(Icons.account_balance_wallet, color: AppColors.primary),
                    const SizedBox(width: 16),
                    Text('SeWaroeng Wallet', style: Theme.of(context).textTheme.labelLarge),
                  ],
                ),
                Text('Sisa: Rp 50.000', style: Theme.of(context).textTheme.bodySmall),
              ],
            ),
          ),
          
          const SizedBox(height: 24),
          
          // Summary
          Text('Ringkasan Belanja', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 8),
          GlassContainer(
            padding: const EdgeInsets.all(16.0),
            child: Column(
              children: [
                _buildSummaryRow('Total Harga (1 Barang)', 'Rp 15.000'),
                const SizedBox(height: 8),
                _buildSummaryRow('Total Ongkos Kirim', 'Rp 10.000'),
                const Divider(color: AppColors.glassBorder, height: 24),
                _buildSummaryRow('Total Tagihan', 'Rp 25.000', isBold: true),
              ],
            ),
          ),
        ],
      ),
      bottomNavigationBar: Container(
        padding: const EdgeInsets.all(16.0),
        decoration: const BoxDecoration(
          color: AppColors.surfaceVariant,
          border: Border(top: BorderSide(color: AppColors.glassBorder)),
        ),
        child: SafeArea(
          child: ElevatedButton(
            onPressed: () {},
            child: const Text('BAYAR SEKARANG'),
          ),
        ),
      ),
    );
  }

  Widget _buildSummaryRow(String label, String value, {bool isBold = false}) {
    return Row(
      mainAxisAlignment: MainAxisAlignment.spaceBetween,
      children: [
        Text(label, style: TextStyle(fontWeight: isBold ? FontWeight.bold : FontWeight.normal)),
        Text(value, style: TextStyle(fontWeight: isBold ? FontWeight.bold : FontWeight.normal)),
      ],
    );
  }
}
