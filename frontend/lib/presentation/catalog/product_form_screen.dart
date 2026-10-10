import 'package:flutter/material.dart';
import '../../core/catalog/catalog_service.dart';
import '../../core/catalog/product_model.dart';
import '../../core/tenant/tenant_service.dart';

class ProductFormScreen extends StatefulWidget {
  final CatalogService catalogService;
  final TenantService tenantService;
  final ProductModel? existingProduct;

  const ProductFormScreen({
    super.key,
    required this.catalogService,
    required this.tenantService,
    this.existingProduct,
  });

  @override
  State<ProductFormScreen> createState() => _ProductFormScreenState();
}

class _ProductFormScreenState extends State<ProductFormScreen> {
  final _formKey = GlobalKey<FormState>();

  // Basic Info
  final _nameController = TextEditingController();
  final _slugController = TextEditingController();
  final _descController = TextEditingController();
  String? _selectedCategoryId;
  String _productType = 'product';

  // Variant Mode: 'standard' vs 'multiple'
  String _variantMode = 'standard';

  // Standard Mode Fields
  final _standardPriceController = TextEditingController();
  final _standardSkuController = TextEditingController();
  final _standardBarcodeController = TextEditingController();

  // Multi-Variant Mode Fields
  final List<Map<String, dynamic>> _variantList = [];

  // Modifiers
  final Set<String> _selectedModifierGroupIds = {};

  // Availability
  final Map<String, bool> _outletAvailabilities = {};

  bool _isSaving = false;

  @override
  void initState() {
    super.initState();
    widget.catalogService.fetchCategories();
    widget.catalogService.fetchModifierGroups();

    if (widget.existingProduct != null) {
      _initExisting(widget.existingProduct!);
    } else {
      // Default: 1 default variant in multi mode
      _variantList.add({
        'name': 'Regular',
        'sku': '',
        'price': 0,
        'barcodes': <String>[''],
        'is_default': true,
      });
      // Outlets default to available
      for (final o in widget.tenantService.outlets) {
        _outletAvailabilities[o.id] = true;
      }
    }
  }

  void _initExisting(ProductModel p) {
    _nameController.text = p.name;
    _slugController.text = p.slug;
    _descController.text = p.description ?? '';
    _selectedCategoryId = p.categoryId;
    _productType = p.productType;

    if (p.isStandardVariant) {
      _variantMode = 'standard';
      final dv = p.defaultVariant;
      if (dv != null) {
        _standardSkuController.text = dv.sku ?? '';
        if (dv.barcodes.isNotEmpty) {
          _standardBarcodeController.text = dv.barcodes.first.barcode;
        }
        if (dv.activePrice != null) {
          _standardPriceController.text = dv.activePrice!.amount.toString();
        } else if (dv.prices.isNotEmpty) {
          _standardPriceController.text = dv.prices.first.amount.toString();
        }
      }
    } else {
      _variantMode = 'multiple';
      for (final v in p.variants) {
        _variantList.add({
          'name': v.name,
          'sku': v.sku ?? '',
          'price':
              v.activePrice?.amount ??
              (v.prices.isNotEmpty ? v.prices.first.amount : 0),
          'barcodes': v.barcodes.map((b) => b.barcode).toList(),
          'is_default': v.isDefault,
        });
      }
    }
  }

  @override
  void dispose() {
    _nameController.dispose();
    _slugController.dispose();
    _descController.dispose();
    _standardPriceController.dispose();
    _standardSkuController.dispose();
    _standardBarcodeController.dispose();
    super.dispose();
  }

  void _addVariantRow() {
    setState(() {
      _variantList.add({
        'name': '',
        'sku': '',
        'price': 0,
        'barcodes': <String>[''],
        'is_default': _variantList.isEmpty,
      });
    });
  }

  void _removeVariantRow(int idx) {
    if (_variantList.length <= 1) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Minimal harus ada 1 varian produk.')),
      );
      return;
    }
    setState(() {
      final wasDefault = _variantList[idx]['is_default'] == true;
      _variantList.removeAt(idx);
      if (wasDefault && _variantList.isNotEmpty) {
        _variantList[0]['is_default'] = true;
      }
    });
  }

  void _setDefaultVariant(int targetIndex) {
    setState(() {
      for (int i = 0; i < _variantList.length; i++) {
        _variantList[i]['is_default'] = (i == targetIndex);
      }
    });
  }

  Future<void> _saveProduct() async {
    if (!_formKey.currentState!.validate()) return;

    final name = _nameController.text.trim();
    final slug = _slugController.text.trim();
    final desc = _descController.text.trim();

    setState(() => _isSaving = true);

    try {
      if (widget.existingProduct != null) {
        // Update product
        final success = await widget.catalogService.updateProduct(
          widget.existingProduct!.id,
          name: name,
          slug: slug.isEmpty ? null : slug,
          categoryId: _selectedCategoryId,
          description: desc.isEmpty ? null : desc,
          modifierGroupIds: _selectedModifierGroupIds.toList(),
        );

        if (success && mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Produk berhasil diperbarui.')),
          );
          Navigator.of(context).pop(true);
        }
      } else {
        // Create product
        if (_variantMode == 'standard') {
          final price = int.tryParse(_standardPriceController.text.trim()) ?? 0;
          final sku = _standardSkuController.text.trim();
          final barcode = _standardBarcodeController.text.trim();

          final created = await widget.catalogService.createProduct(
            name: name,
            slug: slug.isEmpty ? null : slug,
            categoryId: _selectedCategoryId,
            description: desc.isEmpty ? null : desc,
            productType: _productType,
            price: price,
            sku: sku.isEmpty ? null : sku,
            barcode: barcode.isEmpty ? null : barcode,
            modifierGroupIds: _selectedModifierGroupIds.toList(),
          );

          if (created != null) {
            // Apply outlet availabilities if any disabled
            for (final entry in _outletAvailabilities.entries) {
              if (!entry.value) {
                await widget.catalogService.setProductOutletAvailability(
                  productId: created.id,
                  outletId: entry.key,
                  isAvailable: false,
                );
              }
            }

            if (mounted) {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  content: Text('Produk "${created.name}" berhasil dibuat.'),
                ),
              );
              Navigator.of(context).pop(true);
            }
          }
        } else {
          // Multi-variant creation
          final formattedVariants = _variantList.map((v) {
            final barcodes = (v['barcodes'] as List<dynamic>)
                .map((b) => b.toString().trim())
                .where((b) => b.isNotEmpty)
                .toList();

            return {
              'name': (v['name'] as String).trim(),
              'sku': (v['sku'] as String).trim().isEmpty
                  ? null
                  : (v['sku'] as String).trim(),
              'price': v['price'] is int
                  ? v['price']
                  : (int.tryParse(v['price'].toString()) ?? 0),
              'is_default': v['is_default'] == true,
              if (barcodes.isNotEmpty) 'barcodes': barcodes,
            };
          }).toList();

          final created = await widget.catalogService.createProduct(
            name: name,
            slug: slug.isEmpty ? null : slug,
            categoryId: _selectedCategoryId,
            description: desc.isEmpty ? null : desc,
            productType: _productType,
            variants: formattedVariants,
            modifierGroupIds: _selectedModifierGroupIds.toList(),
          );

          if (created != null && mounted) {
            ScaffoldMessenger.of(context).showSnackBar(
              SnackBar(
                content: Text(
                  'Produk multi-varian "${created.name}" berhasil dibuat.',
                ),
              ),
            );
            Navigator.of(context).pop(true);
          }
        }
      }
    } finally {
      if (mounted) {
        setState(() => _isSaving = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final categories = widget.catalogService.categories;
    final modifierGroups = widget.catalogService.modifierGroups;
    final outlets = widget.tenantService.outlets;

    return Scaffold(
      appBar: AppBar(
        title: Text(
          widget.existingProduct != null ? 'Edit Produk' : 'Tambah Produk Baru',
        ),
        backgroundColor: const Color(0xFF0F766E),
        foregroundColor: Colors.white,
        actions: [
          TextButton.icon(
            onPressed: _isSaving ? null : _saveProduct,
            icon: _isSaving
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(
                      strokeWidth: 2,
                      color: Colors.white,
                    ),
                  )
                : const Icon(Icons.check, color: Colors.white),
            label: const Text(
              'Simpan',
              style: TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.bold,
              ),
            ),
          ),
        ],
      ),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            // STEP 1: Basic Information
            _buildSectionHeader('1. Informasi Dasar Produk'),
            const SizedBox(height: 12),
            TextFormField(
              controller: _nameController,
              decoration: const InputDecoration(
                labelText: 'Nama Produk *',
                hintText: 'Misal: Kopi Susu Aren, Nasi Goreng Spesial',
                border: OutlineInputBorder(),
              ),
              validator: (v) => (v == null || v.trim().isEmpty)
                  ? 'Nama produk wajib diisi'
                  : null,
            ),
            const SizedBox(height: 12),
            Row(
              children: [
                Expanded(
                  child: DropdownButtonFormField<String?>(
                    initialValue: _selectedCategoryId,
                    decoration: const InputDecoration(
                      labelText: 'Kategori',
                      border: OutlineInputBorder(),
                    ),
                    items: [
                      const DropdownMenuItem(
                        value: null,
                        child: Text('Tanpa Kategori'),
                      ),
                      ...categories.map(
                        (c) =>
                            DropdownMenuItem(value: c.id, child: Text(c.name)),
                      ),
                    ],
                    onChanged: (val) =>
                        setState(() => _selectedCategoryId = val),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: DropdownButtonFormField<String>(
                    initialValue: _productType,
                    decoration: const InputDecoration(
                      labelText: 'Jenis Produk',
                      border: OutlineInputBorder(),
                    ),
                    items: const [
                      DropdownMenuItem(
                        value: 'product',
                        child: Text('Barang / Fisik'),
                      ),
                      DropdownMenuItem(
                        value: 'service',
                        child: Text('Jasa / Layanan'),
                      ),
                    ],
                    onChanged: (val) =>
                        setState(() => _productType = val ?? 'product'),
                  ),
                ),
              ],
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _descController,
              maxLines: 2,
              decoration: const InputDecoration(
                labelText: 'Deskripsi Singkat (Opsional)',
                border: OutlineInputBorder(),
              ),
            ),

            const SizedBox(height: 24),

            // STEP 2: Variant & Pricing Mode
            _buildSectionHeader('2. Pengaturan Varian & Harga'),
            const SizedBox(height: 8),
            SegmentedButton<String>(
              segments: const [
                ButtonSegment(
                  value: 'standard',
                  label: Text('Varian Standar (Tunggal)'),
                  icon: Icon(Icons.inventory_2_outlined),
                ),
                ButtonSegment(
                  value: 'multiple',
                  label: Text('Multi-Varian'),
                  icon: Icon(Icons.style_outlined),
                ),
              ],
              selected: {_variantMode},
              onSelectionChanged: (set) {
                if (widget.existingProduct == null) {
                  setState(() => _variantMode = set.first);
                }
              },
            ),
            const SizedBox(height: 16),

            if (_variantMode == 'standard') ...[
              // Standard Single Variant Mode
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  color: const Color(0xFFF1F5F9),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: const Color(0xFFCBD5E1)),
                ),
                child: Column(
                  children: [
                    TextFormField(
                      controller: _standardPriceController,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(
                        labelText: 'Harga Jual Standar (Rp) *',
                        prefixText: 'Rp ',
                        border: OutlineInputBorder(),
                        filled: true,
                        fillColor: Colors.white,
                      ),
                      validator: (v) {
                        if (_variantMode == 'standard' &&
                            (v == null || v.trim().isEmpty)) {
                          return 'Harga jual wajib diisi';
                        }
                        return null;
                      },
                    ),
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        Expanded(
                          child: TextFormField(
                            controller: _standardSkuController,
                            decoration: const InputDecoration(
                              labelText: 'SKU (Opsional)',
                              hintText: 'KODE-01',
                              border: OutlineInputBorder(),
                              filled: true,
                              fillColor: Colors.white,
                            ),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: TextFormField(
                            controller: _standardBarcodeController,
                            decoration: const InputDecoration(
                              labelText: 'Barcode (Opsional)',
                              hintText: '899123...',
                              border: OutlineInputBorder(),
                              filled: true,
                              fillColor: Colors.white,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ] else ...[
              // Multi-Variant Mode
              ..._variantList.asMap().entries.map((entry) {
                final idx = entry.key;
                final variant = entry.value;

                return Card(
                  margin: const EdgeInsets.only(bottom: 12),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(10),
                    side: BorderSide(
                      color: variant['is_default'] == true
                          ? const Color(0xFF0F766E)
                          : const Color(0xFFE2E8F0),
                      width: variant['is_default'] == true ? 2 : 1,
                    ),
                  ),
                  child: Padding(
                    padding: const EdgeInsets.all(12),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Row(
                          children: [
                            InkWell(
                              onTap: () => _setDefaultVariant(idx),
                              borderRadius: BorderRadius.circular(4),
                              child: Row(
                                children: [
                                  Icon(
                                    variant['is_default'] == true
                                        ? Icons.radio_button_checked
                                        : Icons.radio_button_unchecked,
                                    color: variant['is_default'] == true
                                        ? const Color(0xFF0F766E)
                                        : const Color(0xFF94A3B8),
                                    size: 20,
                                  ),
                                  const SizedBox(width: 6),
                                  const Text(
                                    'Varian Default',
                                    style: TextStyle(
                                      fontWeight: FontWeight.bold,
                                      fontSize: 13,
                                    ),
                                  ),
                                ],
                              ),
                            ),
                            const Spacer(),
                            IconButton(
                              icon: const Icon(
                                Icons.delete_outline,
                                color: Colors.red,
                              ),
                              onPressed: () => _removeVariantRow(idx),
                            ),
                          ],
                        ),
                        Row(
                          children: [
                            Expanded(
                              flex: 3,
                              child: TextFormField(
                                initialValue: variant['name'] as String,
                                decoration: const InputDecoration(
                                  labelText:
                                      'Nama Varian (misal: Regular, Large)',
                                  isDense: true,
                                  border: OutlineInputBorder(),
                                ),
                                onChanged: (v) => variant['name'] = v,
                              ),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              flex: 2,
                              child: TextFormField(
                                initialValue: variant['price'].toString(),
                                keyboardType: TextInputType.number,
                                decoration: const InputDecoration(
                                  labelText: 'Harga (Rp)',
                                  prefixText: 'Rp ',
                                  isDense: true,
                                  border: OutlineInputBorder(),
                                ),
                                onChanged: (v) =>
                                    variant['price'] = int.tryParse(v) ?? 0,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Row(
                          children: [
                            Expanded(
                              child: TextFormField(
                                initialValue: variant['sku'] as String?,
                                decoration: const InputDecoration(
                                  labelText: 'SKU (Opsional)',
                                  isDense: true,
                                  border: OutlineInputBorder(),
                                ),
                                onChanged: (v) => variant['sku'] = v,
                              ),
                            ),
                            const SizedBox(width: 8),
                            Expanded(
                              child: TextFormField(
                                initialValue:
                                    (variant['barcodes'] as List).isNotEmpty
                                    ? (variant['barcodes'] as List).first
                                          .toString()
                                    : '',
                                decoration: const InputDecoration(
                                  labelText: 'Barcode (Opsional)',
                                  isDense: true,
                                  border: OutlineInputBorder(),
                                ),
                                onChanged: (v) => variant['barcodes'] = [v],
                              ),
                            ),
                          ],
                        ),
                      ],
                    ),
                  ),
                );
              }),
              OutlinedButton.icon(
                onPressed: _addVariantRow,
                icon: const Icon(Icons.add),
                label: const Text('Tambah Varian Lain'),
                style: OutlinedButton.styleFrom(
                  foregroundColor: const Color(0xFF0F766E),
                  side: const BorderSide(color: Color(0xFF0F766E)),
                ),
              ),
            ],

            const SizedBox(height: 24),

            // STEP 3: Modifiers Assignment
            _buildSectionHeader('3. Pilihan Modifier / Opsi Tambahan'),
            const SizedBox(height: 8),
            if (modifierGroups.isEmpty)
              const Text(
                'Belum ada grup modifier. Anda dapat menambahkannya di menu Modifier.',
              )
            else
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: modifierGroups.map((mg) {
                  final isSelected = _selectedModifierGroupIds.contains(mg.id);
                  return FilterChip(
                    label: Text(mg.name),
                    selected: isSelected,
                    selectedColor: const Color(0xFFCCFBF1),
                    checkmarkColor: const Color(0xFF0F766E),
                    onSelected: (val) {
                      setState(() {
                        if (val) {
                          _selectedModifierGroupIds.add(mg.id);
                        } else {
                          _selectedModifierGroupIds.remove(mg.id);
                        }
                      });
                    },
                  );
                }).toList(),
              ),

            const SizedBox(height: 24),

            // STEP 4: Outlet Availability
            if (outlets.isNotEmpty) ...[
              _buildSectionHeader('4. Ketersediaan di Outlet'),
              const SizedBox(height: 8),
              ...outlets.map((o) {
                final isAvail = _outletAvailabilities[o.id] ?? true;
                return SwitchListTile(
                  title: Text(o.name),
                  subtitle: Text(
                    isAvail
                        ? 'Tersedia untuk dijual'
                        : 'Dinonaktifkan di outlet ini',
                  ),
                  value: isAvail,
                  activeThumbColor: const Color(0xFF0F766E),
                  onChanged: (val) {
                    setState(() => _outletAvailabilities[o.id] = val);
                  },
                );
              }),
            ],

            const SizedBox(height: 32),

            ElevatedButton.icon(
              onPressed: _isSaving ? null : _saveProduct,
              icon: _isSaving
                  ? const SizedBox(
                      width: 18,
                      height: 18,
                      child: CircularProgressIndicator(
                        strokeWidth: 2,
                        color: Colors.white,
                      ),
                    )
                  : const Icon(Icons.save),
              label: Text(
                widget.existingProduct != null
                    ? 'Perbarui Produk'
                    : 'Simpan Produk',
                style: const TextStyle(
                  fontSize: 16,
                  fontWeight: FontWeight.bold,
                ),
              ),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF0F766E),
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 16),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10),
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildSectionHeader(String title) {
    return Text(
      title,
      style: const TextStyle(
        fontSize: 15,
        fontWeight: FontWeight.bold,
        color: Color(0xFF0F766E),
      ),
    );
  }
}
