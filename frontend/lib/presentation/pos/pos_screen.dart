import 'package:flutter/material.dart';
import '../../core/customer/customer_models.dart';
import '../../core/customer/customer_service.dart';
import '../../core/pos/pos_models.dart';
import '../../core/pos/pos_service.dart';
import '../../core/receipt/receipt_service.dart';
import 'receipt_screen.dart';

class PosScreen extends StatefulWidget {
  final PosService posService;
  final CustomerService? customerService;
  final ReceiptService? receiptService;
  final String outletId;
  final String outletName;
  final String? activeShiftId;
  final String? cashierUserId;

  const PosScreen({
    super.key,
    required this.posService,
    this.customerService,
    this.receiptService,
    required this.outletId,
    this.outletName = 'Outlet Utama',
    this.activeShiftId,
    this.cashierUserId,
  });

  @override
  State<PosScreen> createState() => _PosScreenState();
}

class _PosScreenState extends State<PosScreen> {
  final TextEditingController _searchController = TextEditingController();
  late final CustomerService _customerService;
  late final ReceiptService _receiptService;
  bool _showCartDrawerOnMobile = false;

  @override
  void initState() {
    super.initState();
    _customerService = widget.customerService ?? CustomerService(apiClient: widget.posService.apiClient);
    _receiptService = widget.receiptService ?? ReceiptService(apiClient: widget.posService.apiClient);
    widget.posService.addListener(_onServiceUpdate);
    widget.posService.fetchCatalog(outletId: widget.outletId);
  }

  @override
  void dispose() {
    widget.posService.removeListener(_onServiceUpdate);
    _searchController.dispose();
    super.dispose();
  }

  void _onServiceUpdate() {
    if (mounted) setState(() {});
  }

  String _formatRupiah(int amountMinor) {
    // IDR format without decimal minor units for cashier display
    final chars = amountMinor.toString().split('').reversed.toList();
    final buffer = StringBuffer();
    for (int i = 0; i < chars.length; i++) {
      if (i > 0 && i % 3 == 0) buffer.write('.');
      buffer.write(chars[i]);
    }
    return 'Rp ${buffer.toString().split('').reversed.join('')}';
  }

  @override
  Widget build(BuildContext context) {
    final service = widget.posService;
    final isDesktop = MediaQuery.of(context).size.width > 900;

    return Scaffold(
      appBar: AppBar(
        title: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text('SeWaroeng POS', style: TextStyle(fontWeight: FontWeight.bold)),
            Text(
              widget.outletName,
              style: const TextStyle(fontSize: 12, color: Colors.white70),
            ),
          ],
        ),
        actions: [
          if (service.isOffline)
            Container(
              margin: const EdgeInsets.symmetric(vertical: 12, horizontal: 4),
              padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
              decoration: BoxDecoration(
                color: Colors.amber.shade700,
                borderRadius: BorderRadius.circular(12),
              ),
              child: const Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Icon(Icons.cloud_off, size: 14, color: Colors.white),
                  SizedBox(width: 4),
                  Text('OFFLINE', style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.bold)),
                ],
              ),
            ),
          if (service.syncService != null)
            IconButton(
              icon: service.syncService!.isSyncing
                  ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white))
                  : const Icon(Icons.sync),
              tooltip: 'Sinkronisasi Data',
              onPressed: () async {
                final count = await service.syncService!.syncPendingOperations();
                if (context.mounted && count > 0) {
                  ScaffoldMessenger.of(context).showSnackBar(
                    SnackBar(content: Text('$count transaksi berhasil disinkronkan!')),
                  );
                }
              },
            ),
          IconButton(
            icon: const Icon(Icons.history),
            tooltip: 'Riwayat Transaksi',
            onPressed: () => _showHistorySheet(context),
          ),
          if (!isDesktop)
            Stack(
              alignment: Alignment.center,
              children: [
                IconButton(
                  icon: const Icon(Icons.shopping_cart_outlined),
                  onPressed: () {
                    setState(() {
                      _showCartDrawerOnMobile = !_showCartDrawerOnMobile;
                    });
                  },
                ),
                if (service.cartTotalItemCount > 0)
                  Positioned(
                    right: 8,
                    top: 8,
                    child: Container(
                      padding: const EdgeInsets.all(4),
                      decoration: const BoxDecoration(
                        color: Colors.red,
                        shape: BoxShape.circle,
                      ),
                      constraints: const BoxConstraints(minWidth: 16, minHeight: 16),
                      child: Text(
                        '${service.cartTotalItemCount}',
                        style: const TextStyle(color: Colors.white, fontSize: 10, fontWeight: FontWeight.bold),
                        textAlign: TextAlign.center,
                      ),
                    ),
                  ),
              ],
            ),
        ],
      ),
      body: service.isLoading && service.products.isEmpty
          ? const Center(child: CircularProgressIndicator())
          : isDesktop
              ? Row(
                  children: [
                    Expanded(flex: 3, child: _buildCatalogSection(service)),
                    const VerticalDivider(width: 1),
                    Expanded(flex: 2, child: _buildCartSection(service)),
                  ],
                )
              : _showCartDrawerOnMobile
                  ? _buildCartSection(service)
                  : _buildCatalogSection(service),
    );
  }

  // ===========================================================================
  // CATALOG SECTION
  // ===========================================================================

  Widget _buildCatalogSection(PosService service) {
    return Column(
      children: [
        // Search & Category Filters
        Padding(
          padding: const EdgeInsets.all(12.0),
          child: TextField(
            controller: _searchController,
            decoration: InputDecoration(
              hintText: 'Cari menu / produk...',
              prefixIcon: const Icon(Icons.search),
              suffixIcon: _searchController.text.isNotEmpty
                  ? IconButton(
                      icon: const Icon(Icons.clear),
                      onPressed: () {
                        _searchController.clear();
                        service.setSearchQuery('');
                      },
                    )
                  : null,
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
              filled: true,
              fillColor: Colors.grey.shade50,
            ),
            onChanged: service.setSearchQuery,
          ),
        ),
        // Categories horizontal list
        SizedBox(
          height: 48,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 12),
            children: [
              Padding(
                padding: const EdgeInsets.only(right: 8.0),
                child: FilterChip(
                  label: const Text('Semua'),
                  selected: service.selectedCategoryId == null,
                  onSelected: (_) => service.selectCategory(null),
                ),
              ),
              ...service.categories.map((cat) {
                return Padding(
                  padding: const EdgeInsets.only(right: 8.0),
                  child: FilterChip(
                    label: Text(cat.name),
                    selected: service.selectedCategoryId == cat.id,
                    onSelected: (_) => service.selectCategory(cat.id),
                  ),
                );
              }),
            ],
          ),
        ),
        const Divider(height: 1),
        // Product Grid
        Expanded(
          child: service.filteredProducts.isEmpty
              ? const Center(child: Text('Tidak ada menu ditemukan.'))
              : GridView.builder(
                  padding: const EdgeInsets.all(12),
                  gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                    maxCrossAxisExtent: 220,
                    childAspectRatio: 0.85,
                    crossAxisSpacing: 12,
                    mainAxisSpacing: 12,
                  ),
                  itemCount: service.filteredProducts.length,
                  itemBuilder: (context, index) {
                    final product = service.filteredProducts[index];
                    return _buildProductCard(product);
                  },
                ),
        ),
      ],
    );
  }

  Widget _buildProductCard(PosCatalogProduct product) {
    final hasPrice = product.hasActivePrice;
    final startingPrice = product.variants.isNotEmpty
        ? (product.variants.first.resolvedPrice?.amount ?? 0)
        : 0;

    return Card(
      elevation: 2,
      clipBehavior: Clip.antiAlias,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: InkWell(
        onTap: hasPrice ? () => _handleProductTap(product) : null,
        child: Opacity(
          opacity: hasPrice ? 1.0 : 0.6,
          child: Padding(
            padding: const EdgeInsets.all(12.0),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Expanded(
                  child: Container(
                    decoration: BoxDecoration(
                      color: Colors.deepPurple.shade50,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Center(
                      child: Icon(
                        Icons.restaurant_menu,
                        size: 40,
                        color: hasPrice ? Colors.deepPurple : Colors.grey,
                      ),
                    ),
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  product.name,
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 4),
                if (!hasPrice)
                  const Text(
                    'Harga belum diatur',
                    style: TextStyle(color: Colors.red, fontSize: 12, fontWeight: FontWeight.bold),
                  )
                else
                  Text(
                    product.variants.length > 1
                        ? 'Mulai ${_formatRupiah(startingPrice)}'
                        : _formatRupiah(startingPrice),
                    style: TextStyle(
                      color: Colors.green.shade800,
                      fontWeight: FontWeight.w600,
                      fontSize: 13,
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  void _handleProductTap(PosCatalogProduct product) {
    if (product.variants.isEmpty) return;

    if (product.variants.length == 1 && product.modifierGroups.isEmpty) {
      // Direct add to cart
      final variant = product.variants.first;
      if (variant.hasPrice) {
        widget.posService.addToCart(product: product, variant: variant);
      }
    } else {
      // Show variant / modifier selection dialog
      _showVariantModifierModal(product);
    }
  }

  // ===========================================================================
  // VARIANT & MODIFIER SELECTION MODAL
  // ===========================================================================

  void _showVariantModifierModal(PosCatalogProduct product) {
    PosCatalogVariant selectedVariant = product.variants.firstWhere(
      (v) => v.hasPrice,
      orElse: () => product.variants.first,
    );
    final List<PosCatalogModifier> selectedModifiers = [];
    final notesController = TextEditingController();

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setModalState) {
            return Padding(
              padding: EdgeInsets.only(
                bottom: MediaQuery.of(context).viewInsets.bottom,
                left: 16,
                right: 16,
                top: 16,
              ),
              child: ConstrainedBox(
                constraints: BoxConstraints(
                  maxHeight: MediaQuery.of(context).size.height * 0.8,
                ),
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Expanded(
                          child: Text(
                            product.name,
                            style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                          ),
                        ),
                        IconButton(
                          icon: const Icon(Icons.close),
                          onPressed: () => Navigator.pop(ctx),
                        ),
                      ],
                    ),
                    const Divider(),
                    Expanded(
                      child: ListView(
                        shrinkWrap: true,
                        children: [
                          if (product.variants.length > 1) ...[
                            const Text(
                              'Pilih Varian:',
                              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                            ),
                            const SizedBox(height: 8),
                            Wrap(
                              spacing: 8,
                              children: product.variants.map((v) {
                                final isSelected = selectedVariant.id == v.id;
                                return ChoiceChip(
                                  label: Text(
                                    '${v.name} (${v.hasPrice ? _formatRupiah(v.resolvedPrice!.amount) : "Tak Ada Harga"})',
                                  ),
                                  selected: isSelected,
                                  onSelected: v.hasPrice
                                      ? (val) {
                                          if (val) setModalState(() => selectedVariant = v);
                                        }
                                      : null,
                                );
                              }).toList(),
                            ),
                            const SizedBox(height: 16),
                          ],
                          ...product.modifierGroups.map((group) {
                            return Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  '${group.name} ${group.isRequired ? "(Wajib)" : "(Opsional)"}',
                                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                ),
                                ...group.modifiers.map((mod) {
                                  final isChecked =
                                      selectedModifiers.any((m) => m.id == mod.id);
                                  return CheckboxListTile(
                                    dense: true,
                                    title: Text(mod.name),
                                    subtitle: Text(
                                      mod.priceAdjustment > 0
                                          ? '+${_formatRupiah(mod.priceAdjustment)}'
                                          : 'Gratis',
                                    ),
                                    value: isChecked,
                                    onChanged: (checked) {
                                      setModalState(() {
                                        if (group.isSingle) {
                                          selectedModifiers.removeWhere((m) =>
                                              group.modifiers.any((gm) => gm.id == m.id));
                                          if (checked == true) selectedModifiers.add(mod);
                                        } else {
                                          if (checked == true) {
                                            selectedModifiers.add(mod);
                                          } else {
                                            selectedModifiers.removeWhere((m) => m.id == mod.id);
                                          }
                                        }
                                      });
                                    },
                                  );
                                }),
                                const SizedBox(height: 12),
                              ],
                            );
                          }),
                          TextField(
                            controller: notesController,
                            decoration: const InputDecoration(
                              labelText: 'Catatan Khusus (e.g. Pedas, Sedikit Gula)',
                              border: OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 16),
                        ],
                      ),
                    ),
                    const Divider(),
                    Padding(
                      padding: const EdgeInsets.symmetric(vertical: 8.0),
                      child: Row(
                        children: [
                          Expanded(
                            child: ElevatedButton.icon(
                              style: ElevatedButton.styleFrom(
                                padding: const EdgeInsets.symmetric(vertical: 14),
                                backgroundColor: Colors.deepPurple,
                                foregroundColor: Colors.white,
                              ),
                              icon: const Icon(Icons.add_shopping_cart),
                              label: const Text('Tambahkan ke Keranjang'),
                              onPressed: selectedVariant.hasPrice
                                  ? () {
                                      widget.posService.addToCart(
                                        product: product,
                                        variant: selectedVariant,
                                        modifiers: selectedModifiers,
                                        notes: notesController.text.trim().isEmpty
                                            ? null
                                            : notesController.text.trim(),
                                      );
                                      Navigator.pop(ctx);
                                    }
                                  : null,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );
  }

  // ===========================================================================
  // CART SECTION
  // ===========================================================================

  Widget _buildCartSection(PosService service) {
    return Container(
      color: Colors.white,
      child: Column(
        children: [
          Container(
            padding: const EdgeInsets.all(12),
            color: Colors.grey.shade100,
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Row(
                  children: [
                    const Icon(Icons.receipt_long, size: 20),
                    const SizedBox(width: 8),
                    Text(
                      service.currentReferenceNumber != null
                          ? service.currentReferenceNumber!
                          : 'Pesanan Baru',
                      style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                    ),
                  ],
                ),
                if (service.cartItems.isNotEmpty)
                  TextButton.icon(
                    style: TextButton.styleFrom(foregroundColor: Colors.red),
                    icon: const Icon(Icons.delete_outline, size: 18),
                    label: const Text('Kosongkan'),
                    onPressed: service.clearCart,
                  ),
              ],
            ),
          ),
          _buildCustomerSelector(service),
          if (service.errorMessage != null)
            Container(
              padding: const EdgeInsets.all(8),
              margin: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: Colors.red.shade50,
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: Colors.red.shade200),
              ),
              child: Row(
                children: [
                  const Icon(Icons.error_outline, color: Colors.red, size: 18),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      service.errorMessage!,
                      style: const TextStyle(color: Colors.red, fontSize: 12),
                    ),
                  ),
                ],
              ),
            ),
          Expanded(
            child: service.cartItems.isEmpty
                ? Center(
                    child: Column(
                      mainAxisAlignment: MainAxisAlignment.center,
                      children: [
                        Icon(Icons.remove_shopping_cart, size: 60, color: Colors.grey.shade300),
                        const SizedBox(height: 8),
                        Text('Keranjang Masih Kosong', style: TextStyle(color: Colors.grey.shade600)),
                      ],
                    ),
                  )
                : ListView.separated(
                    padding: const EdgeInsets.all(12),
                    itemCount: service.cartItems.length,
                    separatorBuilder: (_, _) => const Divider(),
                    itemBuilder: (context, index) {
                      final item = service.cartItems[index];
                      return _buildCartItemTile(service, item);
                    },
                  ),
          ),
          _buildCartSummary(service),
        ],
      ),
    );
  }

  Widget _buildCartItemTile(PosService service, PosCartItem item) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${item.product.name} - ${item.variant.name}',
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
              ),
              if (item.selectedModifiers.isNotEmpty)
                Text(
                  item.selectedModifiers.map((m) => m.name).join(', '),
                  style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
                ),
              if (item.notes != null)
                Text(
                  'Catatan: ${item.notes}',
                  style: TextStyle(color: Colors.orange.shade800, fontSize: 11, fontStyle: FontStyle.italic),
                ),
              const SizedBox(height: 4),
              Text(
                _formatRupiah(item.subtotalMinor),
                style: const TextStyle(fontWeight: FontWeight.w600, color: Colors.deepPurple),
              ),
            ],
          ),
        ),
        Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            IconButton(
              icon: const Icon(Icons.remove_circle_outline, size: 20),
              onPressed: () => service.updateCartItemQuantity(item.id, item.quantity - 1),
            ),
            Text('${item.quantity}', style: const TextStyle(fontWeight: FontWeight.bold)),
            IconButton(
              icon: const Icon(Icons.add_circle_outline, size: 20),
              onPressed: () => service.updateCartItemQuantity(item.id, item.quantity + 1),
            ),
          ],
        ),
      ],
    );
  }

  Widget _buildCustomerSelector(PosService service) {
    final customer = service.selectedCustomer;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: customer != null ? Colors.deepPurple.shade50 : Colors.grey.shade50,
        border: Border(bottom: BorderSide(color: Colors.grey.shade300)),
      ),
      child: Row(
        children: [
          Icon(
            customer != null ? Icons.person : Icons.person_outline,
            size: 20,
            color: customer != null ? Colors.deepPurple : Colors.grey.shade600,
          ),
          const SizedBox(width: 8),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  children: [
                    Flexible(
                      child: Text(
                        customer != null ? customer.name : 'Pelanggan Umum (Walk-in)',
                        style: TextStyle(
                          fontSize: 13,
                          fontWeight: FontWeight.bold,
                          color: customer != null ? Colors.deepPurple.shade900 : Colors.black87,
                        ),
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    if (customer != null) ...[
                      const SizedBox(width: 6),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1),
                        decoration: BoxDecoration(
                          color: Colors.deepPurple.shade100,
                          borderRadius: BorderRadius.circular(4),
                        ),
                        child: Text(
                          customer.segment.toUpperCase(),
                          style: TextStyle(
                            fontSize: 9,
                            fontWeight: FontWeight.bold,
                            color: Colors.deepPurple.shade800,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
                if (customer?.phone != null)
                  Text(
                    customer!.phone!,
                    style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
                  ),
              ],
            ),
          ),
          TextButton(
            onPressed: () => _showCustomerPickerModal(service),
            child: Text(
              customer != null ? 'Ganti' : 'Pilih',
              style: const TextStyle(fontSize: 12),
            ),
          ),
          if (customer != null)
            IconButton(
              icon: const Icon(Icons.close, size: 16),
              color: Colors.grey.shade600,
              padding: EdgeInsets.zero,
              constraints: const BoxConstraints(),
              onPressed: () => service.setSelectedCustomer(null),
              tooltip: 'Hapus Pelanggan',
            ),
        ],
      ),
    );
  }

  void _showCustomerPickerModal(PosService service) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return _CustomerPickerSheet(
          customerService: _customerService,
          currentCustomer: service.selectedCustomer,
          onSelected: (customer) {
            service.setSelectedCustomer(customer);
            Navigator.pop(ctx);
          },
          onAddNew: () {
            Navigator.pop(ctx);
            _showQuickCreateCustomerDialog(service);
          },
        );
      },
    );
  }

  void _showQuickCreateCustomerDialog(PosService service) {
    final nameCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final emailCtrl = TextEditingController();
    final notesCtrl = TextEditingController();
    bool marketingOptIn = false;
    bool isSubmitting = false;
    String? formError;

    showDialog(
      context: context,
      builder: (ctx) {
        return StatefulBuilder(
          builder: (context, setDialogState) {
            return AlertDialog(
              title: const Text('Tambah Pelanggan Cepat'),
              content: SingleChildScrollView(
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    if (formError != null)
                      Container(
                        padding: const EdgeInsets.all(8),
                        margin: const EdgeInsets.only(bottom: 12),
                        decoration: BoxDecoration(
                          color: Colors.red.shade50,
                          borderRadius: BorderRadius.circular(6),
                          border: Border.all(color: Colors.red.shade200),
                        ),
                        child: Text(
                          formError!,
                          style: const TextStyle(color: Colors.red, fontSize: 12),
                        ),
                      ),
                    TextField(
                      controller: nameCtrl,
                      decoration: const InputDecoration(
                        labelText: 'Nama Lengkap *',
                        border: OutlineInputBorder(),
                        isDense: true,
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: phoneCtrl,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(
                        labelText: 'Nomor WhatsApp / HP',
                        hintText: '0812... atau +62812...',
                        border: OutlineInputBorder(),
                        isDense: true,
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: emailCtrl,
                      keyboardType: TextInputType.emailAddress,
                      decoration: const InputDecoration(
                        labelText: 'Email (Opsional)',
                        border: OutlineInputBorder(),
                        isDense: true,
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: notesCtrl,
                      decoration: const InputDecoration(
                        labelText: 'Catatan (Opsional)',
                        border: OutlineInputBorder(),
                        isDense: true,
                      ),
                    ),
                    const SizedBox(height: 8),
                    CheckboxListTile(
                      contentPadding: EdgeInsets.zero,
                      dense: true,
                      title: const Text('Setuju menerima info promo (Marketing Opt-in)', style: TextStyle(fontSize: 12)),
                      value: marketingOptIn,
                      onChanged: (val) {
                        setDialogState(() {
                          marketingOptIn = val ?? false;
                        });
                      },
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
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.deepPurple,
                    foregroundColor: Colors.white,
                  ),
                  onPressed: isSubmitting
                      ? null
                      : () async {
                          final name = nameCtrl.text.trim();
                          if (name.isEmpty) {
                            setDialogState(() {
                              formError = 'Nama pelanggan wajib diisi.';
                            });
                            return;
                          }

                          setDialogState(() {
                            isSubmitting = true;
                            formError = null;
                          });

                          final messenger = ScaffoldMessenger.of(context);
                          final created = await _customerService.createCustomer(
                            name: name,
                            phone: phoneCtrl.text.trim().isEmpty ? null : phoneCtrl.text.trim(),
                            email: emailCtrl.text.trim().isEmpty ? null : emailCtrl.text.trim(),
                            notes: notesCtrl.text.trim().isEmpty ? null : notesCtrl.text.trim(),
                            marketingOptIn: marketingOptIn,
                          );

                          if (created != null) {
                            service.setSelectedCustomer(created);
                            if (ctx.mounted) Navigator.pop(ctx);
                            messenger.showSnackBar(
                              SnackBar(
                                content: Text('Pelanggan ${created.name} berhasil ditambahkan!'),
                                backgroundColor: Colors.green,
                              ),
                            );
                          } else {
                            setDialogState(() {
                              isSubmitting = false;
                              formError = _customerService.errorMessage ?? 'Gagal membuat pelanggan.';
                            });
                          }
                        },
                  child: isSubmitting
                      ? const SizedBox(
                          width: 16,
                          height: 16,
                          child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                        )
                      : const Text('Simpan & Pilih'),
                ),
              ],
            );
          },
        );
      },
    );
  }

  Widget _buildCartSummary(PosService service) {
    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.grey.shade50,
        border: Border(top: BorderSide(color: Colors.grey.shade300)),
      ),
      child: Column(
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text('Subtotal:'),
              Text(_formatRupiah(service.cartSubtotalMinor)),
            ],
          ),
          const SizedBox(height: 4),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Row(
                children: [
                  const Text('Diskon:'),
                  IconButton(
                    icon: const Icon(Icons.edit, size: 14),
                    padding: EdgeInsets.zero,
                    constraints: const BoxConstraints(),
                    onPressed: () => _showDiscountDialog(service),
                  ),
                ],
              ),
              Text(
                service.discountMinor > 0 ? '-${_formatRupiah(service.discountMinor)}' : 'Rp 0',
                style: TextStyle(color: service.discountMinor > 0 ? Colors.red : Colors.black),
              ),
            ],
          ),
          const SizedBox(height: 4),
          const Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text('Pajak (Tax):', style: TextStyle(color: Colors.grey)),
              Text('Rp 0', style: TextStyle(color: Colors.grey)),
            ],
          ),
          const Divider(),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text('TOTAL:', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              Text(
                _formatRupiah(service.cartTotalMinor),
                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 18, color: Colors.deepPurple),
              ),
            ],
          ),
          const SizedBox(height: 12),
          // Action Buttons: Simpan Draft & Finalisasi
          Row(
            children: [
              Expanded(
                child: OutlinedButton(
                  onPressed: service.cartItems.isNotEmpty && !service.isFinalizing
                      ? () async {
                          final saved = await service.saveOpenSale(outletId: widget.outletId);
                          if (saved != null && mounted) {
                            ScaffoldMessenger.of(context).showSnackBar(
                              SnackBar(content: Text('Disimpan sebagai draft (${saved.referenceNumber})')),
                            );
                          }
                        }
                      : null,
                  child: const Text('Simpan Draft'),
                ),
              ),
              const SizedBox(width: 8),
              Expanded(
                child: ElevatedButton(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: Colors.deepPurple,
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 12),
                  ),
                  onPressed: service.cartItems.isNotEmpty && !service.isFinalizing
                      ? () => _confirmFinalize(service)
                      : null,
                  child: service.isFinalizing
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2),
                        )
                      : const Text('Finalisasi Sale'),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          // Placeholder Payment Notice (Section 28 Explicit Requirement)
          Container(
            padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 10),
            decoration: BoxDecoration(
              color: Colors.amber.shade50,
              borderRadius: BorderRadius.circular(6),
              border: Border.all(color: Colors.amber.shade200),
            ),
            child: const Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: [
                Icon(Icons.info_outline, size: 14, color: Colors.orange),
                SizedBox(width: 6),
                Flexible(
                  child: Text(
                    'Pembayaran akan tersedia pada modul Payment.',
                    style: TextStyle(fontSize: 11, color: Colors.brown),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  void _showDiscountDialog(PosService service) {
    final controller = TextEditingController(
      text: service.discountMinor > 0 ? service.discountMinor.toString() : '',
    );

    showDialog(
      context: context,
      builder: (ctx) {
        return AlertDialog(
          title: const Text('Atur Diskon Transaksi'),
          content: TextField(
            controller: controller,
            keyboardType: TextInputType.number,
            decoration: const InputDecoration(
              labelText: 'Jumlah Diskon (Rp)',
              border: OutlineInputBorder(),
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Batal'),
            ),
            ElevatedButton(
              onPressed: () {
                final val = int.tryParse(controller.text) ?? 0;
                service.setDiscount(val);
                Navigator.pop(ctx);
              },
              child: const Text('Terapkan'),
            ),
          ],
        );
      },
    );
  }

  // ===========================================================================
  // FINALIZE CONFIRMATION & SUCCESS MODAL
  // ===========================================================================

  void _confirmFinalize(PosService service) {
    showDialog(
      context: context,
      builder: (ctx) {
        return AlertDialog(
          title: const Text('Konfirmasi Finalisasi Transaksi'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Total Transaksi: ${_formatRupiah(service.cartTotalMinor)}'),
              const SizedBox(height: 4),
              Text('Jumlah Item: ${service.cartTotalItemCount} item'),
              const SizedBox(height: 12),
              const Text(
                'Transaksi yang difinalisasi akan secara atomik memotong stok inventaris dan merekam COGS berdasarkan MWA.',
                style: TextStyle(fontSize: 12, color: Colors.grey),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Batal'),
            ),
            ElevatedButton(
              style: ElevatedButton.styleFrom(backgroundColor: Colors.deepPurple, foregroundColor: Colors.white),
              onPressed: () async {
                Navigator.pop(ctx);
                PosSale? finalizedSale;
                if (service.isOffline) {
                  finalizedSale = await service.finalizeOfflineSale(
                    tenantId: '',
                    outletId: widget.outletId,
                    shiftId: widget.activeShiftId,
                    cashierUserId: widget.cashierUserId ?? 'cashier-local',
                    paymentMethod: 'cash',
                  );
                } else {
                  finalizedSale = await service.finalizeCurrentSale(outletId: widget.outletId);
                }
                if (finalizedSale != null && mounted) {
                  _showSuccessDialog(finalizedSale);
                }
              },
              child: const Text('Konfirmasi & Finalisasi'),
            ),
          ],
        );
      },
    );
  }

  void _showSuccessDialog(PosSale sale) {
    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (ctx) {
        return AlertDialog(
          title: const Row(
            children: [
              Icon(Icons.check_circle, color: Colors.green, size: 28),
              SizedBox(width: 8),
              Text('Transaksi Berhasil'),
            ],
          ),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Nomor Referensi: ${sale.referenceNumber}', style: const TextStyle(fontWeight: FontWeight.bold)),
              const SizedBox(height: 4),
              Text('Status: ${sale.status.toUpperCase()}', style: const TextStyle(color: Colors.green)),
              const SizedBox(height: 4),
              Text('Total: ${_formatRupiah(sale.totalMinor)}', style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold)),
              const Divider(height: 24),
              const Text(
                'Pembayaran akan tersedia pada modul Payment.',
                style: TextStyle(fontSize: 12, color: Colors.brown, fontStyle: FontStyle.italic),
              ),
            ],
          ),
          actions: [
            OutlinedButton.icon(
              icon: const Icon(Icons.receipt_long),
              label: const Text('Lihat Struk'),
              onPressed: () {
                Navigator.pop(ctx);
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (_) => ReceiptScreen(
                      saleId: sale.id,
                      receiptService: _receiptService,
                    ),
                  ),
                );
              },
            ),
            ElevatedButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Selesai / Transaksi Baru'),
            ),
          ],
        );
      },
    );
  }

  Color _syncStatusColor(OfflineSyncStatus status) {
    switch (status) {
      case OfflineSyncStatus.localSaved:
        return Colors.blue;
      case OfflineSyncStatus.syncPending:
        return Colors.amber.shade800;
      case OfflineSyncStatus.syncing:
        return Colors.purple;
      case OfflineSyncStatus.synced:
        return Colors.green;
      case OfflineSyncStatus.needsReview:
        return Colors.deepOrange;
      case OfflineSyncStatus.failed:
        return Colors.red;
    }
  }

  // ===========================================================================
  // TRANSACTION HISTORY SHEET
  // ===========================================================================

  void _showHistorySheet(BuildContext context) {
    if (!widget.posService.isOffline) {
      widget.posService.fetchSales();
    }

    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) {
        return FutureBuilder<List<PosSale>>(
          future: widget.posService.syncService?.getLocalSales(widget.outletId) ??
              Future.value(<PosSale>[]),
          builder: (context, localSnapshot) {
            return StatefulBuilder(
              builder: (context, setSheetState) {
                final localSales = localSnapshot.data ?? <PosSale>[];
                final onlineSales = widget.posService.sales;
                final allSales = <PosSale>[
                  ...localSales.where((ls) => !onlineSales.any((os) => os.id == ls.id)),
                  ...onlineSales,
                ];

                return Container(
                  height: MediaQuery.of(context).size.height * 0.75,
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          const Text(
                            'Riwayat Transaksi POS',
                            style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                          ),
                          IconButton(
                            icon: const Icon(Icons.close),
                            onPressed: () => Navigator.pop(ctx),
                          ),
                        ],
                      ),
                      const Divider(),
                      Expanded(
                        child: allSales.isEmpty
                            ? const Center(child: Text('Belum ada transaksi.'))
                            : ListView.separated(
                                itemCount: allSales.length,
                                separatorBuilder: (_, _) => const Divider(),
                                itemBuilder: (context, index) {
                                  final sale = allSales[index];
                                  Color statusColor = Colors.grey;
                                  if (sale.isFinalized) statusColor = Colors.green;
                                  if (sale.isCancelled) statusColor = Colors.red;
                                  if (sale.isOpen) statusColor = Colors.orange;

                                  return ListTile(
                                    leading: Icon(Icons.receipt, color: statusColor),
                                    title: Row(
                                      children: [
                                        Expanded(
                                          child: Text(
                                            sale.referenceNumber,
                                            style: const TextStyle(fontWeight: FontWeight.bold),
                                          ),
                                        ),
                                        Container(
                                          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                          decoration: BoxDecoration(
                                            color: _syncStatusColor(sale.syncStatus).withValues(alpha: 0.15),
                                            borderRadius: BorderRadius.circular(6),
                                            border: Border.all(
                                              color: _syncStatusColor(sale.syncStatus),
                                              width: 0.5,
                                            ),
                                          ),
                                          child: Text(
                                            sale.syncStatus.label,
                                            style: TextStyle(
                                              fontSize: 10,
                                              fontWeight: FontWeight.bold,
                                              color: _syncStatusColor(sale.syncStatus),
                                            ),
                                          ),
                                        ),
                                      ],
                                    ),
                                    subtitle: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Text(
                                          'Status: ${sale.status} • ${_formatRupiah(sale.totalMinor)}',
                                        ),
                                        if (sale.syncError != null && sale.syncError!.isNotEmpty)
                                          Text(
                                            'Catatan Sinkronisasi: ${sale.syncError}',
                                            style: const TextStyle(fontSize: 11, color: Colors.red),
                                          ),
                                      ],
                                    ),
                                    trailing: sale.isOpen
                                        ? OutlinedButton(
                                            style: OutlinedButton.styleFrom(foregroundColor: Colors.red),
                                            onPressed: () async {
                                              final ok = await widget.posService.cancelSale(sale.id);
                                              if (ok) {
                                                setSheetState(() {});
                                              }
                                            },
                                            child: const Text('Batalkan'),
                                          )
                                        : sale.isFinalized
                                            ? OutlinedButton.icon(
                                                icon: const Icon(Icons.receipt, size: 16),
                                                label: const Text('Struk'),
                                                onPressed: () {
                                                  Navigator.pop(ctx);
                                                  Navigator.push(
                                                    context,
                                                    MaterialPageRoute(
                                                      builder: (_) => ReceiptScreen(
                                                        saleId: sale.id,
                                                        receiptService: _receiptService,
                                                      ),
                                                    ),
                                                  );
                                                },
                                              )
                                            : null,
                                  );
                                },
                              ),
                      ),
                    ],
                  ),
                );
              },
            );
          },
        );
      },
    );
  }
}

class _CustomerPickerSheet extends StatefulWidget {
  final CustomerService customerService;
  final CustomerModel? currentCustomer;
  final ValueChanged<CustomerModel?> onSelected;
  final VoidCallback onAddNew;

  const _CustomerPickerSheet({
    required this.customerService,
    required this.currentCustomer,
    required this.onSelected,
    required this.onAddNew,
  });

  @override
  State<_CustomerPickerSheet> createState() => _CustomerPickerSheetState();
}

class _CustomerPickerSheetState extends State<_CustomerPickerSheet> {
  final TextEditingController _searchCtrl = TextEditingController();
  List<CustomerModel> _results = [];
  bool _searching = false;

  @override
  void initState() {
    super.initState();
    _results = widget.customerService.customers;
    _performSearch('');
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _performSearch(String query) async {
    setState(() => _searching = true);
    final list = await widget.customerService.fetchCustomers(search: query);
    if (mounted) {
      setState(() {
        _results = list;
        _searching = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      height: MediaQuery.of(context).size.height * 0.75,
      padding: const EdgeInsets.all(16),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              const Text(
                'Pilih Pelanggan POS',
                style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
              ),
              IconButton(
                icon: const Icon(Icons.close),
                onPressed: () => Navigator.pop(context),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Row(
            children: [
              Expanded(
                child: TextField(
                  controller: _searchCtrl,
                  decoration: InputDecoration(
                    hintText: 'Cari nama / telepon / email...',
                    prefixIcon: const Icon(Icons.search),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                    isDense: true,
                  ),
                  onChanged: (val) => _performSearch(val),
                ),
              ),
              const SizedBox(width: 8),
              ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.deepPurple,
                  foregroundColor: Colors.white,
                  padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 12),
                ),
                onPressed: widget.onAddNew,
                icon: const Icon(Icons.person_add, size: 18),
                label: const Text('Baru'),
              ),
            ],
          ),
          const SizedBox(height: 12),
          ListTile(
            leading: const CircleAvatar(
              backgroundColor: Colors.grey,
              child: Icon(Icons.person_off, color: Colors.white, size: 18),
            ),
            title: const Text('Pelanggan Umum (Walk-in)', style: TextStyle(fontWeight: FontWeight.bold)),
            subtitle: const Text('Tanpa pencatatan identitas pelanggan'),
            trailing: widget.currentCustomer == null ? const Icon(Icons.check, color: Colors.green) : null,
            onTap: () => widget.onSelected(null),
          ),
          const Divider(),
          if (_searching)
            const Padding(
              padding: EdgeInsets.all(16),
              child: Center(child: CircularProgressIndicator()),
            )
          else if (_results.isEmpty)
            Expanded(
              child: Center(
                child: Column(
                  mainAxisAlignment: MainAxisAlignment.center,
                  children: [
                    Icon(Icons.person_search, size: 48, color: Colors.grey.shade400),
                    const SizedBox(height: 8),
                    Text('Tidak ada pelanggan ditemukan', style: TextStyle(color: Colors.grey.shade600)),
                    const SizedBox(height: 8),
                    OutlinedButton.icon(
                      onPressed: widget.onAddNew,
                      icon: const Icon(Icons.add),
                      label: const Text('Tambah Pelanggan Baru'),
                    ),
                  ],
                ),
              ),
            )
          else
            Expanded(
              child: ListView.separated(
                itemCount: _results.length,
                separatorBuilder: (_, _) => const Divider(height: 1),
                itemBuilder: (context, index) {
                  final customer = _results[index];
                  final isSelected = widget.currentCustomer?.id == customer.id;

                  return ListTile(
                    leading: CircleAvatar(
                      backgroundColor: isSelected ? Colors.deepPurple : Colors.deepPurple.shade50,
                      child: Text(
                        customer.name.isNotEmpty ? customer.name[0].toUpperCase() : '?',
                        style: TextStyle(
                          color: isSelected ? Colors.white : Colors.deepPurple,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                    title: Text(customer.name, style: const TextStyle(fontWeight: FontWeight.bold)),
                    subtitle: Text(
                      [
                        if (customer.phone != null) customer.phone!,
                        if (customer.email != null) customer.email!,
                        'Segmen: ${customer.segment.toUpperCase()}',
                      ].join(' • '),
                      style: const TextStyle(fontSize: 12),
                    ),
                    trailing: isSelected ? const Icon(Icons.check, color: Colors.green) : null,
                    onTap: () => widget.onSelected(customer),
                  );
                },
              ),
            ),
        ],
      ),
    );
  }
}
