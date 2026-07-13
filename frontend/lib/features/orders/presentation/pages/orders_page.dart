// Neura Cakrawira Solusi | SeWaroeng
// Orders feature - Presentation Layer
import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../shared/widgets/glass_container.dart';
import '../providers/orders_provider.dart';

class OrdersPage extends ConsumerWidget {
  const OrdersPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return DefaultTabController(
      length: 3,
      child: Scaffold(
        appBar: AppBar(
          title: const Text('Pesanan Saya'),
          backgroundColor: AppColors.surface,
          bottom: const TabBar(
            tabs: [
              Tab(text: 'Berlangsung'),
              Tab(text: 'Selesai'),
              Tab(text: 'Dibatalkan'),
            ],
            indicatorColor: AppColors.primary,
            labelColor: AppColors.primary,
            unselectedLabelColor: AppColors.grey600,
          ),
        ),
        body: TabBarView(
          children: [
            _buildOrderList(context, ref, 'Berlangsung'),
            _buildOrderList(context, ref, 'Selesai'),
            _buildOrderList(context, ref, 'Dibatalkan'),
          ],
        ),
      ),
    );
  }

  Widget _buildOrderList(BuildContext context, WidgetRef ref, String status) {
    final ordersAsync = ref.watch(ordersProvider);

    return ordersAsync.when(
      data: (orders) {
        final filteredOrders = orders.where((o) => _mapStatus(o.status) == status).toList();
        
        if (filteredOrders.isEmpty) {
          return const Center(child: Text('Tidak ada pesanan.'));
        }

        return ListView.builder(
          padding: const EdgeInsets.all(16.0),
          itemCount: filteredOrders.length,
          itemBuilder: (context, index) {
            final order = filteredOrders[index];
            return Padding(
              padding: const EdgeInsets.only(bottom: 16.0),
              child: GlassContainer(
                padding: const EdgeInsets.all(16.0),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text('Order: ${order.id}', style: Theme.of(context).textTheme.labelSmall),
                        Container(
                          padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                          decoration: BoxDecoration(
                            color: status == 'Berlangsung' ? AppColors.info.withOpacity(0.2) : 
                                   status == 'Selesai' ? AppColors.success.withOpacity(0.2) : 
                                   AppColors.error.withOpacity(0.2),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: Text(
                            status,
                            style: TextStyle(
                              fontSize: 10,
                              color: status == 'Berlangsung' ? AppColors.info : 
                                     status == 'Selesai' ? AppColors.success : 
                                     AppColors.error,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const Divider(color: AppColors.glassBorder, height: 24),
                    Row(
                      children: [
                        Container(
                          width: 60,
                          height: 60,
                          decoration: BoxDecoration(
                            color: AppColors.surfaceVariant,
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: const Icon(Icons.image, color: AppColors.grey600),
                        ),
                        const SizedBox(width: 16),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text('Barang Pesanan', style: Theme.of(context).textTheme.titleSmall),
                              Text('${order.itemCount} Barang', style: Theme.of(context).textTheme.bodySmall),
                            ],
                          ),
                        ),
                      ],
                    ),
                    const Divider(color: AppColors.glassBorder, height: 24),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text('Total Belanja', style: Theme.of(context).textTheme.bodySmall),
                            Text('Rp ${order.total.toStringAsFixed(0)}', style: Theme.of(context).textTheme.labelLarge),
                          ],
                        ),
                        OutlinedButton(
                          onPressed: () {},
                          child: const Text('Lihat Detail'),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
      loading: () => const Center(child: CircularProgressIndicator()),
      error: (e, _) => Center(child: Text('Error: $e')),
    );
  }

  String _mapStatus(String apiStatus) {
    switch (apiStatus) {
      case 'completed':
        return 'Selesai';
      case 'cancelled':
        return 'Dibatalkan';
      default:
        return 'Berlangsung';
    }
  }
}
