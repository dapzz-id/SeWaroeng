// Neura Cakrawira Solusi | SeWaroeng
// Superadmin Dashboard Page

import 'package:flutter/material.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../shared/widgets/glass_container.dart';

class SuperadminDashboardPage extends StatelessWidget {
  const SuperadminDashboardPage({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Superadmin Dashboard'),
        backgroundColor: AppColors.surface,
        actions: [
          IconButton(
            icon: const Icon(Icons.notifications),
            tooltip: 'Broadcast Notification',
            onPressed: () {
              _showBroadcastDialog(context);
            },
          )
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Platform Stats
            Row(
              children: [
                Expanded(
                  child: GlassContainer(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      children: [
                        const Icon(Icons.store, color: AppColors.primary, size: 32),
                        const SizedBox(height: 8),
                        const Text('Total Tenant'),
                        Text('1,245', style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold)),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 16),
                Expanded(
                  child: GlassContainer(
                    padding: const EdgeInsets.all(16),
                    child: Column(
                      children: [
                        const Icon(Icons.people, color: Colors.greenAccent, size: 32),
                        const SizedBox(height: 8),
                        const Text('Total User'),
                        Text('45,210', style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold)),
                      ],
                    ),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
            GlassContainer(
              padding: const EdgeInsets.all(16),
              child: Column(
                children: [
                  const Icon(Icons.attach_money, color: Colors.orangeAccent, size: 32),
                  const SizedBox(height: 8),
                  const Text('Platform Revenue (Subscriptions)'),
                  Text('Rp 311.250.000', style: Theme.of(context).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold, color: Colors.orangeAccent)),
                ],
              ),
            ),
            const SizedBox(height: 24),
            
            // List of Tenants (Management)
            const Text('Manajemen UMKM', style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold)),
            const SizedBox(height: 16),
            ListView.builder(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              itemCount: 3,
              itemBuilder: (context, index) {
                final isSuspended = index == 2;
                return Card(
                  color: AppColors.surfaceVariant,
                  child: ListTile(
                    leading: const CircleAvatar(child: Icon(Icons.storefront)),
                    title: Text('Warung UMKM ${index + 1}'),
                    subtitle: Text(isSuspended ? 'Status: Suspended' : 'Status: Active'),
                    trailing: PopupMenuButton<String>(
                      onSelected: (value) {
                        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$value Action')));
                      },
                      itemBuilder: (context) => [
                        const PopupMenuItem(value: 'suspend', child: Text('Suspend Tenant')),
                        const PopupMenuItem(value: 'ban', child: Text('Banned Tenant', style: TextStyle(color: Colors.red))),
                      ],
                    ),
                  ),
                );
              },
            ),
          ],
        ),
      ),
    );
  }

  void _showBroadcastDialog(BuildContext context) {
    showDialog(
      context: context,
      builder: (ctx) {
        return AlertDialog(
          backgroundColor: AppColors.surface,
          title: const Text('Kirim Broadcast Notifikasi'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                decoration: const InputDecoration(hintText: 'Judul Notifikasi'),
              ),
              const SizedBox(height: 16),
              TextField(
                maxLines: 3,
                decoration: const InputDecoration(hintText: 'Isi Notifikasi (Promo/Maintenance)'),
              ),
            ],
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
            ElevatedButton(
              onPressed: () {
                Navigator.pop(ctx);
                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Notifikasi Global Terkirim!')));
              },
              child: const Text('Kirim'),
            ),
          ],
        );
      },
    );
  }
}
