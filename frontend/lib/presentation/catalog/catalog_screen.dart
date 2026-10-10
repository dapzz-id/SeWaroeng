import 'package:flutter/material.dart';
import '../../core/catalog/catalog_service.dart';
import '../../core/catalog/product_model.dart';
import '../../core/tenant/tenant_service.dart';
import 'category_management_sheet.dart';
import 'modifier_management_sheet.dart';
import 'product_form_screen.dart';

class CatalogScreen extends StatefulWidget {
  final CatalogService catalogService;
  final TenantService tenantService;

  const CatalogScreen({
    super.key,
    required this.catalogService,
    required this.tenantService,
  });

  @override
  State<CatalogScreen> createState() => _CatalogScreenState();
}

class _CatalogScreenState extends State<CatalogScreen> {
  final _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadInitialData();
  }

  void _loadInitialData() {
    widget.catalogService.fetchCategories();
    widget.catalogService.fetchProducts(
      outletId: widget.tenantService.activeOutlet?.id,
    );
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  void _openCategoryManagement() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) =>
          CategoryManagementSheet(catalogService: widget.catalogService),
    );
  }

  void _openModifierManagement() {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (_) =>
          ModifierManagementSheet(catalogService: widget.catalogService),
    );
  }

  Future<void> _openProductForm([ProductModel? existing]) async {
    final result = await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => ProductFormScreen(
          catalogService: widget.catalogService,
          tenantService: widget.tenantService,
          existingProduct: existing,
        ),
      ),
    );

    if (result == true) {
      widget.catalogService.fetchProducts(
        outletId: widget.tenantService.activeOutlet?.id,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFFF8FAFC),
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Katalog Produk',
              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 18),
            ),
            Text(
              widget.tenantService.activeTenant?.name ?? 'SeWaroeng Merchant',
              style: const TextStyle(fontSize: 12, color: Colors.white70),
            ),
          ],
        ),
        backgroundColor: const Color(0xFF0F766E),
        foregroundColor: Colors.white,
        actions: [
          IconButton(
            tooltip: 'Kelola Kategori',
            icon: const Icon(Icons.category_outlined),
            onPressed: _openCategoryManagement,
          ),
          IconButton(
            tooltip: 'Kelola Modifier',
            icon: const Icon(Icons.tune),
            onPressed: _openModifierManagement,
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _openProductForm(),
        backgroundColor: const Color(0xFF0F766E),
        foregroundColor: Colors.white,
        icon: const Icon(Icons.add),
        label: const Text('Tambah Produk'),
      ),
      body: AnimatedBuilder(
        animation: Listenable.merge([
          widget.catalogService,
          widget.tenantService,
        ]),
        builder: (context, _) {
          final service = widget.catalogService;
          final categories = service.categories;
          final products = service.products;
          final outlets = widget.tenantService.outlets;
          final activeOutlet = widget.tenantService.activeOutlet;

          return RefreshIndicator(
            onRefresh: () async {
              await service.fetchCategories();
              await service.fetchProducts(outletId: activeOutlet?.id);
            },
            child: Column(
              children: [
                // Top Filter & Search Section
                Container(
                  color: Colors.white,
                  padding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 12,
                  ),
                  child: Column(
                    children: [
                      // Search Bar
                      TextField(
                        controller: _searchController,
                        decoration: InputDecoration(
                          hintText: 'Cari nama produk, SKU, barcode...',
                          prefixIcon: const Icon(
                            Icons.search,
                            color: Color(0xFF64748B),
                          ),
                          suffixIcon: _searchController.text.isNotEmpty
                              ? IconButton(
                                  icon: const Icon(Icons.clear),
                                  onPressed: () {
                                    _searchController.clear();
                                    service.setSearchQuery(null);
                                  },
                                )
                              : null,
                          isDense: true,
                          filled: true,
                          fillColor: const Color(0xFFF1F5F9),
                          border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(10),
                            borderSide: BorderSide.none,
                          ),
                        ),
                        onSubmitted: (val) => service.setSearchQuery(
                          val.trim().isEmpty ? null : val.trim(),
                        ),
                      ),

                      // Outlet Selector if multiple outlets exist
                      if (outlets.length > 1) ...[
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            const Icon(
                              Icons.storefront,
                              size: 16,
                              color: Color(0xFF64748B),
                            ),
                            const SizedBox(width: 6),
                            const Text(
                              'Harga & Stok Outlet:',
                              style: TextStyle(
                                fontSize: 12,
                                color: Color(0xFF64748B),
                              ),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: DropdownButton<String?>(
                                value:
                                    service.selectedOutletId ??
                                    activeOutlet?.id,
                                isDense: true,
                                underline: const SizedBox(),
                                style: const TextStyle(
                                  fontSize: 13,
                                  fontWeight: FontWeight.w600,
                                  color: Color(0xFF0F766E),
                                ),
                                items: [
                                  const DropdownMenuItem(
                                    value: null,
                                    child: Text('Harga Standar (Semua Outlet)'),
                                  ),
                                  ...outlets.map(
                                    (o) => DropdownMenuItem(
                                      value: o.id,
                                      child: Text(o.name),
                                    ),
                                  ),
                                ],
                                onChanged: (val) {
                                  service.setOutletContext(val);
                                },
                              ),
                            ),
                          ],
                        ),
                      ],

                      const SizedBox(height: 8),

                      // Horizontal Category Filter Chips
                      SingleChildScrollView(
                        scrollDirection: Axis.horizontal,
                        child: Row(
                          children: [
                            ChoiceChip(
                              label: const Text('Semua'),
                              selected: service.selectedCategoryId == null,
                              selectedColor: const Color(0xFFCCFBF1),
                              labelStyle: TextStyle(
                                color: service.selectedCategoryId == null
                                    ? const Color(0xFF0F766E)
                                    : const Color(0xFF475569),
                                fontWeight: service.selectedCategoryId == null
                                    ? FontWeight.bold
                                    : FontWeight.normal,
                              ),
                              onSelected: (_) =>
                                  service.setCategoryFilter(null),
                            ),
                            const SizedBox(width: 6),
                            ...categories.map((c) {
                              final isSelected =
                                  service.selectedCategoryId == c.id;
                              return Padding(
                                padding: const EdgeInsets.only(right: 6),
                                child: ChoiceChip(
                                  label: Text(c.name),
                                  selected: isSelected,
                                  selectedColor: const Color(0xFFCCFBF1),
                                  labelStyle: TextStyle(
                                    color: isSelected
                                        ? const Color(0xFF0F766E)
                                        : const Color(0xFF475569),
                                    fontWeight: isSelected
                                        ? FontWeight.bold
                                        : FontWeight.normal,
                                  ),
                                  onSelected: (_) => service.setCategoryFilter(
                                    isSelected ? null : c.id,
                                  ),
                                ),
                              );
                            }),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),

                // Error message banner
                if (service.errorMessage != null)
                  Container(
                    width: double.infinity,
                    color: Colors.red.shade50,
                    padding: const EdgeInsets.symmetric(
                      horizontal: 16,
                      vertical: 8,
                    ),
                    child: Row(
                      children: [
                        Icon(
                          Icons.error_outline,
                          size: 18,
                          color: Colors.red.shade700,
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            service.errorMessage!,
                            style: TextStyle(
                              color: Colors.red.shade800,
                              fontSize: 13,
                            ),
                          ),
                        ),
                        TextButton(
                          onPressed: () =>
                              service.fetchProducts(outletId: activeOutlet?.id),
                          child: const Text('Coba Lagi'),
                        ),
                      ],
                    ),
                  ),

                // Products List / Status
                Expanded(
                  child: service.isLoading
                      ? const Center(
                          child: CircularProgressIndicator(
                            color: Color(0xFF0F766E),
                          ),
                        )
                      : products.isEmpty
                      ? _buildEmptyState()
                      : ListView.builder(
                          padding: const EdgeInsets.only(
                            left: 16,
                            right: 16,
                            top: 12,
                            bottom: 80,
                          ),
                          itemCount: products.length,
                          itemBuilder: (context, index) {
                            final product = products[index];
                            return _buildProductCard(product);
                          },
                        ),
                ),
              ],
            ),
          );
        },
      ),
    );
  }

  Widget _buildEmptyState() {
    return Center(
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            Icons.inventory_2_outlined,
            size: 64,
            color: Colors.grey.shade400,
          ),
          const SizedBox(height: 16),
          const Text(
            'Belum ada produk di katalog',
            style: TextStyle(
              fontSize: 16,
              fontWeight: FontWeight.bold,
              color: Color(0xFF334155),
            ),
          ),
          const SizedBox(height: 8),
          const Text(
            'Mulai dengan menambahkan produk pertama Anda.',
            style: TextStyle(color: Color(0xFF64748B), fontSize: 13),
          ),
          const SizedBox(height: 16),
          ElevatedButton.icon(
            onPressed: () => _openProductForm(),
            icon: const Icon(Icons.add),
            label: const Text('Tambah Produk'),
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF0F766E),
              foregroundColor: Colors.white,
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildProductCard(ProductModel product) {
    final variantCount = product.variants.length;
    final isAvailable = product.isAvailable;

    return Card(
      elevation: 0,
      margin: const EdgeInsets.only(bottom: 10),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: Color(0xFFE2E8F0)),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => _openProductForm(product),
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              // Product Image or Icon placeholder
              Container(
                width: 52,
                height: 52,
                decoration: BoxDecoration(
                  color: const Color(0xFFF1F5F9),
                  borderRadius: BorderRadius.circular(8),
                ),
                child: const Icon(
                  Icons.fastfood_outlined,
                  color: Color(0xFF0F766E),
                  size: 26,
                ),
              ),
              const SizedBox(width: 14),

              // Product Info
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        Expanded(
                          child: Text(
                            product.name,
                            style: const TextStyle(
                              fontSize: 15,
                              fontWeight: FontWeight.bold,
                              color: Color(0xFF1E293B),
                            ),
                          ),
                        ),
                        // Availability Tag
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 6,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: isAvailable
                                ? const Color(0xFFDCFCE7)
                                : const Color(0xFFFEF3C7),
                            borderRadius: BorderRadius.circular(4),
                          ),
                          child: Text(
                            isAvailable ? 'Tersedia' : 'Nonaktif',
                            style: TextStyle(
                              fontSize: 11,
                              fontWeight: FontWeight.bold,
                              color: isAvailable
                                  ? const Color(0xFF15803D)
                                  : const Color(0xFFB45309),
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 4),

                    // Badges: Category & Variant Count
                    Row(
                      children: [
                        if (product.category != null) ...[
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 6,
                              vertical: 2,
                            ),
                            decoration: BoxDecoration(
                              color: const Color(0xFFE0F2FE),
                              borderRadius: BorderRadius.circular(4),
                            ),
                            child: Text(
                              product.category!.name,
                              style: const TextStyle(
                                fontSize: 11,
                                color: Color(0xFF0369A1),
                              ),
                            ),
                          ),
                          const SizedBox(width: 6),
                        ],
                        Text(
                          variantCount > 1
                              ? '$variantCount Varian'
                              : '1 Varian',
                          style: const TextStyle(
                            fontSize: 12,
                            color: Color(0xFF64748B),
                          ),
                        ),
                        if (product.displaySku != null) ...[
                          const Text(
                            ' • ',
                            style: TextStyle(color: Color(0xFF94A3B8)),
                          ),
                          Text(
                            product.displaySku!,
                            style: const TextStyle(
                              fontSize: 12,
                              color: Color(0xFF64748B),
                            ),
                          ),
                        ],
                      ],
                    ),

                    const SizedBox(height: 8),

                    // Price
                    Text(
                      product.formattedPrice,
                      style: const TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF0F766E),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
