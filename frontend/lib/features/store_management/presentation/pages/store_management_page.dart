// Neura Cakrawira Solusi | SeWaroeng
// Store Management feature - Presentation Layer
import 'package:flutter/material.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../shared/widgets/glass_container.dart';

class StoreManagementPage extends StatelessWidget {
  const StoreManagementPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Kelola Toko'),
        backgroundColor: AppColors.surface,
      ),
      body: ListView(
        padding: const EdgeInsets.all(16.0),
        children: [
          // Store Info
          GlassContainer(
            padding: const EdgeInsets.all(24.0),
            child: Row(
              children: [
                Container(
                  width: 60,
                  height: 60,
                  decoration: BoxDecoration(
                    color: AppColors.primary,
                    borderRadius: BorderRadius.circular(8),
                  ),
                  child: const Center(
                    child: Icon(Icons.store, color: Colors.white, size: 30),
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Toko Demo', style: Theme.of(context).textTheme.titleLarge),
                      const SizedBox(height: 4),
                      Text('Domain: tokodemo.sewaroeng.id', style: Theme.of(context).textTheme.bodySmall),
                    ],
                  ),
                ),
                IconButton(
                  icon: const Icon(Icons.edit, color: AppColors.primary),
                  onPressed: () {},
                ),
              ],
            ),
          ),
          
          const SizedBox(height: 24),
          Text('Menu Manajemen', style: Theme.of(context).textTheme.titleMedium),
          const SizedBox(height: 12),
          
          _buildMenuTile(context, Icons.inventory_2_outlined, 'Produk & Stok', 'Kelola katalog dan inventaris'),
          _buildMenuTile(context, Icons.point_of_sale_outlined, 'Kasir (POS)', 'Buka aplikasi kasir toko'),
          _buildMenuTile(context, Icons.local_shipping_outlined, 'Kurir Toko', 'Atur jarak dan tarif pengiriman'),
          _buildMenuTile(context, Icons.people_outline, 'Staff', 'Kelola akses karyawan'),
          _buildMenuTile(context, Icons.analytics_outlined, 'Laporan Penjualan', 'Lihat statistik pendapatan'),
        ],
      ),
    );
  }

  Widget _buildMenuTile(BuildContext context, IconData icon, String title, String subtitle) {
    return Card(
      margin: const EdgeInsets.only(bottom: 12),
      child: ListTile(
        leading: Container(
          padding: const EdgeInsets.all(8),
          decoration: BoxDecoration(
            color: AppColors.primary.withOpacity(0.1),
            shape: BoxShape.circle,
          ),
          child: Icon(icon, color: AppColors.primary),
        ),
        title: Text(title),
        subtitle: Text(subtitle),
        trailing: const Icon(Icons.chevron_right),
        onTap: () {},
      ),
    );
  }
}
