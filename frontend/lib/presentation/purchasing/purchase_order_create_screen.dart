import 'package:flutter/material.dart';
import '../../core/inventory/inventory_service.dart';
import '../../core/purchasing/purchasing_service.dart';

class PurchaseOrderCreateScreen extends StatefulWidget {
  final PurchasingService purchasingService;
  final InventoryService inventoryService;

  const PurchaseOrderCreateScreen({
    super.key,
    required this.purchasingService,
    required this.inventoryService,
  });

  @override
  State<PurchaseOrderCreateScreen> createState() =>
      _PurchaseOrderCreateScreenState();
}

class _PurchaseOrderCreateScreenState extends State<PurchaseOrderCreateScreen> {
  String? _selectedSupplierId;
  String? _selectedLocationId;
  final _notesController = TextEditingController();
  final List<_OrderItemInput> _items = [];
  bool _isSubmitting = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      widget.purchasingService.fetchSuppliers(status: 'active');
      widget.inventoryService.fetchLocations();
      widget.inventoryService.fetchItems();
    });
  }

  @override
  void dispose() {
    _notesController.dispose();
    for (final item in _items) {
      item.dispose();
    }
    super.dispose();
  }

  void _addItem() {
    setState(() {
      _items.add(_OrderItemInput());
    });
  }

  void _removeItem(int index) {
    setState(() {
      _items[index].dispose();
      _items.removeAt(index);
    });
  }

  Future<void> _submitCreatePO() async {
    if (_selectedSupplierId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Pilih supplier terlebih dahulu'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    if (_selectedLocationId == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Pilih lokasi tujuan penerimaan'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    if (_items.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Tambahkan minimal 1 item pesanan'),
          backgroundColor: Colors.orange,
        ),
      );
      return;
    }

    final payloadItems = <Map<String, dynamic>>[];
    for (final item in _items) {
      if (item.inventoryItemId == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Pastikan semua item telah dipilih'),
            backgroundColor: Colors.orange,
          ),
        );
        return;
      }
      final qty = double.tryParse(item.quantityController.text.trim()) ?? 0.0;
      if (qty <= 0) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Kuantitas pesanan harus lebih dari 0'),
            backgroundColor: Colors.orange,
          ),
        );
        return;
      }
      final cost = int.tryParse(item.costController.text.trim()) ?? 0;

      payloadItems.add({
        'inventory_item_id': item.inventoryItemId,
        'ordered_quantity': qty,
        'unit_cost_minor': cost,
      });
    }

    setState(() => _isSubmitting = true);

    try {
      final po = await widget.purchasingService.createPurchaseOrder(
        supplierId: _selectedSupplierId!,
        destinationLocationId: _selectedLocationId!,
        notes: _notesController.text.trim().isEmpty
            ? null
            : _notesController.text.trim(),
        items: payloadItems,
      );

      if (mounted) {
        if (po != null) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                'Purchase Order ${po.referenceNumber} berhasil dibuat!',
              ),
              backgroundColor: Colors.green,
            ),
          );
          Navigator.of(context).pop(true);
        } else {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                widget.purchasingService.errorMessage ??
                    'Gagal membuat Purchase Order',
              ),
              backgroundColor: Colors.red,
            ),
          );
        }
      }
    } finally {
      if (mounted) {
        setState(() => _isSubmitting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final suppliers = widget.purchasingService.activeSuppliers;
    final locations = widget.inventoryService.locations;
    final inventoryItems = widget.inventoryService.items;

    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        title: const Text(
          'Buat Purchase Order Baru',
          style: TextStyle(color: Colors.white),
        ),
        backgroundColor: const Color(0xFF1E293B),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Supplier Selection
            const Text(
              'Supplier / Pemasok *',
              style: TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.bold,
              ),
            ),
            const SizedBox(height: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              decoration: BoxDecoration(
                color: const Color(0xFF1E293B),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFF334155)),
              ),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  key: const Key('po_supplier_dropdown'),
                  isExpanded: true,
                  value: _selectedSupplierId,
                  hint: const Text(
                    'Pilih Supplier Aktif',
                    style: TextStyle(color: Color(0xFF94A3B8)),
                  ),
                  dropdownColor: const Color(0xFF1E293B),
                  style: const TextStyle(color: Colors.white),
                  items: suppliers.map((s) {
                    return DropdownMenuItem(
                      value: s.id,
                      child: Text('${s.name} (${s.code})'),
                    );
                  }).toList(),
                  onChanged: (val) => setState(() => _selectedSupplierId = val),
                ),
              ),
            ),
            const SizedBox(height: 16),

            // Destination Location Selection
            const Text(
              'Lokasi Gudang / Outlet Tujuan *',
              style: TextStyle(
                color: Colors.white,
                fontWeight: FontWeight.bold,
              ),
            ),
            const SizedBox(height: 6),
            Container(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              decoration: BoxDecoration(
                color: const Color(0xFF1E293B),
                borderRadius: BorderRadius.circular(8),
                border: Border.all(color: const Color(0xFF334155)),
              ),
              child: DropdownButtonHideUnderline(
                child: DropdownButton<String>(
                  key: const Key('po_location_dropdown'),
                  isExpanded: true,
                  value: _selectedLocationId,
                  hint: const Text(
                    'Pilih Lokasi Tujuan',
                    style: TextStyle(color: Color(0xFF94A3B8)),
                  ),
                  dropdownColor: const Color(0xFF1E293B),
                  style: const TextStyle(color: Colors.white),
                  items: locations.map((loc) {
                    return DropdownMenuItem(
                      value: loc.id,
                      child: Text('${loc.name} (${loc.type})'),
                    );
                  }).toList(),
                  onChanged: (val) => setState(() => _selectedLocationId = val),
                ),
              ),
            ),
            const SizedBox(height: 16),

            // Notes
            TextField(
              controller: _notesController,
              maxLines: 2,
              style: const TextStyle(color: Colors.white),
              decoration: const InputDecoration(
                labelText: 'Catatan / Keterangan PO (Opsional)',
                labelStyle: TextStyle(color: Color(0xFF94A3B8)),
                filled: true,
                fillColor: Color(0xFF1E293B),
                border: OutlineInputBorder(
                  borderSide: BorderSide(color: Color(0xFF334155)),
                ),
              ),
            ),
            const SizedBox(height: 24),

            // Items Section
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                const Text(
                  'Item Barang yang Dipesan',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 16,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                TextButton.icon(
                  key: const Key('add_po_item_row_button'),
                  icon: const Icon(Icons.add_circle, color: Color(0xFF38BDF8)),
                  label: const Text(
                    'Tambah Baris Item',
                    style: TextStyle(color: Color(0xFF38BDF8)),
                  ),
                  onPressed: _addItem,
                ),
              ],
            ),
            const SizedBox(height: 8),

            if (_items.isEmpty)
              Container(
                padding: const EdgeInsets.all(24),
                decoration: BoxDecoration(
                  color: const Color(0xFF1E293B),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: const Color(0xFF334155)),
                ),
                child: Center(
                  child: Column(
                    children: [
                      const Icon(
                        Icons.shopping_cart_outlined,
                        color: Color(0xFF64748B),
                        size: 40,
                      ),
                      const SizedBox(height: 8),
                      const Text(
                        'Belum ada item pesanan yang ditambahkan',
                        style: TextStyle(color: Color(0xFF94A3B8)),
                      ),
                      const SizedBox(height: 12),
                      ElevatedButton(
                        style: ElevatedButton.styleFrom(
                          backgroundColor: const Color(0xFF0284C7),
                        ),
                        onPressed: _addItem,
                        child: const Text(
                          'Tambah Item Pertama',
                          style: TextStyle(color: Colors.white),
                        ),
                      ),
                    ],
                  ),
                ),
              ),

            ..._items.asMap().entries.map((entry) {
              final index = entry.key;
              final item = entry.value;

              return Card(
                color: const Color(0xFF1E293B),
                margin: const EdgeInsets.only(bottom: 12),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                  side: const BorderSide(color: Color(0xFF334155)),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(12),
                  child: Column(
                    children: [
                      Row(
                        children: [
                          Expanded(
                            child: DropdownButtonHideUnderline(
                              child: DropdownButton<String>(
                                isExpanded: true,
                                value: item.inventoryItemId,
                                hint: const Text(
                                  'Pilih Bahan/Barang *',
                                  style: TextStyle(color: Color(0xFF94A3B8)),
                                ),
                                dropdownColor: const Color(0xFF1E293B),
                                style: const TextStyle(color: Colors.white),
                                items: inventoryItems.map((invItem) {
                                  return DropdownMenuItem(
                                    value: invItem.id,
                                    child: Text(
                                      '${invItem.name} (${invItem.sku ?? invItem.baseUnit})',
                                    ),
                                  );
                                }).toList(),
                                onChanged: (val) {
                                  setState(() => item.inventoryItemId = val);
                                },
                              ),
                            ),
                          ),
                          IconButton(
                            icon: const Icon(
                              Icons.delete_outline,
                              color: Colors.red,
                            ),
                            onPressed: () => _removeItem(index),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          Expanded(
                            child: TextField(
                              controller: item.quantityController,
                              keyboardType:
                                  const TextInputType.numberWithOptions(
                                    decimal: true,
                                  ),
                              style: const TextStyle(color: Colors.white),
                              decoration: const InputDecoration(
                                labelText: 'Kuantitas *',
                                labelStyle: TextStyle(color: Color(0xFF94A3B8)),
                                filled: true,
                                fillColor: Color(0xFF0F172A),
                                border: OutlineInputBorder(
                                  borderSide: BorderSide.none,
                                ),
                              ),
                            ),
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: TextField(
                              controller: item.costController,
                              keyboardType: TextInputType.number,
                              style: const TextStyle(color: Colors.white),
                              decoration: const InputDecoration(
                                labelText: 'Harga Beli Satuan (Rp) *',
                                labelStyle: TextStyle(color: Color(0xFF94A3B8)),
                                filled: true,
                                fillColor: Color(0xFF0F172A),
                                border: OutlineInputBorder(
                                  borderSide: BorderSide.none,
                                ),
                              ),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              );
            }),

            const SizedBox(height: 24),
            ElevatedButton(
              key: const Key('submit_create_po_button'),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF0284C7),
                padding: const EdgeInsets.symmetric(vertical: 16),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10),
                ),
              ),
              onPressed: _isSubmitting ? null : _submitCreatePO,
              child: _isSubmitting
                  ? const CircularProgressIndicator(color: Colors.white)
                  : const Text(
                      'Simpan Purchase Order (Draft)',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 16,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}

class _OrderItemInput {
  String? inventoryItemId;
  final TextEditingController quantityController = TextEditingController();
  final TextEditingController costController = TextEditingController();

  void dispose() {
    quantityController.dispose();
    costController.dispose();
  }
}
