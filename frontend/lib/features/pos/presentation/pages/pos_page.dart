// Neura Cakrawira Solusi | SeWaroeng
// POS feature - Presentation Layer
import 'package:flutter/material.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../shared/widgets/glass_container.dart';
import '../widgets/barcode_scanner_dialog.dart';

import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../providers/pos_provider.dart';
import '../../../product/presentation/providers/product_provider.dart';

class PosPage extends ConsumerWidget {
  const PosPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final posState = ref.watch(posProvider);
    final posNotifier = ref.read(posProvider.notifier);
    // POS layout usually fits tablets well, splitting screen.
    // For MVP, we'll do a simple list + summary.
    return Scaffold(
      appBar: AppBar(
        title: const Text('Kasir (POS)'),
        backgroundColor: AppColors.surface,
        actions: [
          IconButton(
            icon: const Icon(Icons.sync),
            tooltip: 'Sinkronisasi Offline',
            onPressed: () async {
              try {
                await posNotifier.syncPending();
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('✅ Sinkronisasi selesai!')),
                  );
                }
              } catch (e) {
                if (context.mounted) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text('❌ Gagal sync: $e')),
                  );
                }
              }
            },
          ),
        ],
      ),
      body: Row(
        children: [
          // Left Side: Product List
          Expanded(
            flex: 2,
            child: Column(
              children: [
                Padding(
                  padding: const EdgeInsets.all(16.0),
                  child: TextField(
                    decoration: InputDecoration(
                      hintText: 'Cari produk atau scan barcode...',
                      prefixIcon: const Icon(Icons.search),
                      suffixIcon: IconButton(
                        icon: const Icon(Icons.qr_code_scanner),
                        onPressed: () async {
                          final code = await showDialog<String>(
                            context: context,
                            builder: (context) => const BarcodeScannerDialog(),
                          );
                          if (code != null && context.mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Scanned: $code')));
                            // TODO: Add to cart based on scanned code
                          }
                        },
                      ),
                      filled: true,
                      fillColor: AppColors.surfaceVariant,
                    ),
                  ),
                ),
                Expanded(
                  child: ref.watch(productsProvider).when(
                    data: (products) {
                      if (products.isEmpty) {
                        return const Center(child: Text('Tidak ada produk.'));
                      }
                      return GridView.builder(
                        padding: const EdgeInsets.all(16.0),
                        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                          crossAxisCount: 3,
                          crossAxisSpacing: 16,
                          mainAxisSpacing: 16,
                          childAspectRatio: 0.8,
                        ),
                        itemCount: products.length,
                        itemBuilder: (context, index) {
                          final product = products[index];
                          
                          return InkWell(
                            onTap: () {
                              posNotifier.addItem(product.id, product.name, product.price, 1);
                            },
                            child: GlassContainer(
                              padding: const EdgeInsets.all(8),
                              child: Column(
                                children: [
                                  Expanded(
                                    child: Container(
                                      decoration: BoxDecoration(
                                        color: AppColors.surfaceVariant,
                                        borderRadius: BorderRadius.circular(4),
                                        image: product.imageUrl != null
                                            ? DecorationImage(
                                                image: NetworkImage(product.imageUrl!),
                                                fit: BoxFit.cover,
                                              )
                                            : null,
                                      ),
                                      child: product.imageUrl == null
                                          ? const Center(child: Icon(Icons.image, color: AppColors.grey600))
                                          : null,
                                    ),
                                  ),
                                  const SizedBox(height: 8),
                                  Text(product.name, maxLines: 1, overflow: TextOverflow.ellipsis),
                                  Text('Rp ${product.price.toStringAsFixed(0)}', style: const TextStyle(color: AppColors.primary)),
                                ],
                              ),
                            ),
                          );
                        },
                      );
                    },
                    loading: () => const Center(child: CircularProgressIndicator()),
                    error: (err, stack) => Center(child: Text('Error: $err')),
                  ),
                ),
              ],
            ),
          ),
          
          // Right Side: Current Transaction
          Expanded(
            flex: 1,
            child: Container(
              color: AppColors.surfaceVariant,
              child: Column(
                children: [
                  Container(
                    padding: const EdgeInsets.all(16.0),
                    color: AppColors.surface,
                    width: double.infinity,
                    child: const Text('Transaksi Baru', style: TextStyle(fontWeight: FontWeight.bold)),
                  ),
                  Expanded(
                    child: ListView.builder(
                      itemCount: posState.cartItems.length,
                      itemBuilder: (context, index) {
                        final item = posState.cartItems[index];
                        return ListTile(
                          title: Text(item.productName),
                          subtitle: Text('${item.quantity} x Rp ${item.price.toStringAsFixed(0)}'),
                          trailing: Row(
                            mainAxisSize: MainAxisSize.min,
                            children: [
                              Text('Rp ${item.subtotal.toStringAsFixed(0)}'),
                              IconButton(
                                icon: const Icon(Icons.remove_circle_outline, color: Colors.red),
                                onPressed: () {
                                  posNotifier.updateQuantity(item.productId, item.quantity - 1);
                                },
                              ),
                            ],
                          ),
                        );
                      },
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.all(16.0),
                    color: AppColors.surface,
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            const Text('Total'),
                            Text('Rp ${posState.total.toStringAsFixed(0)}', style: Theme.of(context).textTheme.titleLarge?.copyWith(color: AppColors.primary)),
                          ],
                        ),
                        const SizedBox(height: 16),
                        ElevatedButton(
                          onPressed: posState.cartItems.isEmpty ? null : () async {
                            try {
                              // Simulate checkout with exact amount for now
                              posNotifier.setAmountPaid(posState.total);
                              
                              // Retrieve the transaction before clearing the cart for printing
                              // final tx = posState.copyWith();
                              
                              await posNotifier.checkout(tenantId: "dummy-tenant", staffId: "dummy-staff");
                              if (context.mounted) {
                                ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Transaksi Offline Tersimpan!')));
                                
                                // Ask to print receipt
                                showDialog(
                                  context: context,
                                  builder: (ctx) => AlertDialog(
                                    title: const Text('Cetak Struk?'),
                                    content: const Text('Apakah Anda ingin mencetak struk untuk transaksi ini?'),
                                    actions: [
                                      TextButton(
                                        onPressed: () => Navigator.pop(ctx),
                                        child: const Text('Tidak'),
                                      ),
                                      ElevatedButton(
                                        onPressed: () {
                                          Navigator.pop(ctx);
                                          // Fake printing logic using the copied state
                                          // In reality, checkout should return the transaction
                                          // ref.read(posPrinterServiceProvider).printReceipt(transaction, "SeWaroeng Dummy");
                                          ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('Membuka dialog print...')));
                                        },
                                        child: const Text('Cetak'),
                                      ),
                                    ],
                                  ),
                                );
                              }
                            } catch (e) {
                              if (context.mounted) {
                                ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('Error: $e')));
                              }
                            }
                          },
                          child: const Text('BAYAR'),
                        ),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
