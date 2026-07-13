// Neura Cakrawira Solusi | SeWaroeng
// Product feature - Presentation Layer
import 'package:flutter/material.dart';
import '../../../../core/theme/app_colors.dart';

class ProductDetailPage extends StatelessWidget {
  final String productId;

  const ProductDetailPage({super.key, required this.productId});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Detail Produk'),
        backgroundColor: AppColors.surface,
        elevation: 0,
      ),
      body: SingleChildScrollView(
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Product Image Placeholder
            Container(
              height: 300,
              color: AppColors.surfaceVariant,
              child: const Center(
                child: Icon(Icons.image, size: 80, color: AppColors.grey600),
              ),
            ),
            
            // Product Info
            Padding(
              padding: const EdgeInsets.all(16.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Produk Demo $productId', style: Theme.of(context).textTheme.headlineSmall),
                  const SizedBox(height: 8),
                  Text(
                    'Rp 15.000', 
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(color: AppColors.primary),
                  ),
                  const SizedBox(height: 16),
                  
                  // Stock Indicator
                  Row(
                    children: [
                      const Icon(Icons.inventory_2_outlined, size: 16, color: AppColors.success),
                      const SizedBox(width: 8),
                      Text('Stok Tersedia: 10', style: Theme.of(context).textTheme.bodyMedium),
                    ],
                  ),
                  
                  const SizedBox(height: 24),
                  Text('Deskripsi', style: Theme.of(context).textTheme.titleMedium),
                  const SizedBox(height: 8),
                  Text(
                    'Ini adalah deskripsi produk demo. SeWaroeng mendukung produk fisik maupun digital (PPOB) dengan integrasi multi-tenant.',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
      bottomNavigationBar: Container(
        padding: const EdgeInsets.all(16.0),
        decoration: const BoxDecoration(
          color: AppColors.surfaceVariant,
          border: Border(top: BorderSide(color: AppColors.glassBorder)),
        ),
        child: SafeArea(
          child: Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: () {},
                  child: const Text('Beli Langsung'),
                ),
              ),
              const SizedBox(width: 16),
              Expanded(
                child: ElevatedButton.icon(
                  onPressed: () {},
                  icon: const Icon(Icons.add_shopping_cart),
                  label: const Text('Keranjang'),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
