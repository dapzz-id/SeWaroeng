// Neura Cakrawira Solusi | SeWaroeng
// Profile feature - Presentation Layer
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../shared/widgets/glass_container.dart';

class ProfilePage extends ConsumerWidget {
  const ProfilePage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Profil'),
        backgroundColor: AppColors.surface,
        elevation: 0,
      ),
      body: ListView(
        padding: const EdgeInsets.all(16.0),
        children: [
          // Profile Header
          GlassContainer(
            padding: const EdgeInsets.all(24.0),
            child: Row(
              children: [
                const CircleAvatar(
                  radius: 30,
                  backgroundColor: AppColors.primaryLight,
                  child: Text('UD', style: TextStyle(color: Colors.white, fontSize: 20)),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('User Demo', style: Theme.of(context).textTheme.titleLarge),
                      const SizedBox(height: 4),
                      Text('user@demo.com', style: Theme.of(context).textTheme.bodyMedium),
                    ],
                  ),
                ),
              ],
            ),
          ),
          const SizedBox(height: 24),
          
          // Menu
          _buildMenuTile(context, Icons.location_on_outlined, 'Daftar Alamat'),
          _buildMenuTile(context, Icons.account_balance_wallet_outlined, 'Saldo Wallet'),
          _buildMenuTile(context, Icons.devices_outlined, 'Sesi Aktif'),
          _buildMenuTile(context, Icons.settings_outlined, 'Pengaturan'),
          
          const SizedBox(height: 32),
          
          // Logout Button
          OutlinedButton.icon(
            onPressed: () {
              // ref.read(authNotifierProvider.notifier).logout();
              context.go('/login');
            },
            icon: const Icon(Icons.logout, color: AppColors.error),
            label: const Text('Keluar', style: TextStyle(color: AppColors.error)),
            style: OutlinedButton.styleFrom(
              side: const BorderSide(color: AppColors.error),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildMenuTile(BuildContext context, IconData icon, String title) {
    return ListTile(
      leading: Icon(icon, color: AppColors.primary),
      title: Text(title),
      trailing: const Icon(Icons.chevron_right, color: AppColors.grey600),
      onTap: () {},
    );
  }
}
