import 'package:flutter/material.dart';
import '../../core/inventory/inventory_models.dart';
import '../../core/inventory/inventory_service.dart';

class StockAdjustmentDialog extends StatefulWidget {
  final InventoryService inventoryService;
  final InventoryItemModel? initialItem;
  final InventoryLocationModel? initialLocation;

  const StockAdjustmentDialog({
    super.key,
    required this.inventoryService,
    this.initialItem,
    this.initialLocation,
  });

  @override
  State<StockAdjustmentDialog> createState() => _StockAdjustmentDialogState();
}

class _StockAdjustmentDialogState extends State<StockAdjustmentDialog> {
  final _formKey = GlobalKey<FormState>();
  late InventoryLocationModel? _selectedLocation;
  late InventoryItemModel? _selectedItem;

  final _deltaController = TextEditingController();
  final _noteController = TextEditingController();

  String _reason = 'correction';
  bool _isSubmitting = false;

  final List<Map<String, String>> _reasons = [
    {'value': 'correction', 'label': 'Koreksi Stok'},
    {'value': 'damaged', 'label': 'Barang Rusak'},
    {'value': 'expired', 'label': 'Kedaluwarsa'},
    {'value': 'lost', 'label': 'Barang Hilang'},
    {'value': 'found', 'label': 'Ditemukan'},
    {'value': 'waste', 'label': 'Waste / Terbuang'},
  ];

  @override
  void initState() {
    super.initState();
    _selectedLocation =
        widget.initialLocation ?? widget.inventoryService.selectedLocation;
    _selectedItem = widget.initialItem;
  }

  @override
  void dispose() {
    _deltaController.dispose();
    _noteController.dispose();
    super.dispose();
  }

  double get _currentOnHand {
    if (_selectedItem == null) return 0.0;
    if (_selectedLocation != null && _selectedItem!.locationBalance != null) {
      return _selectedItem!.locationBalance!.onHand;
    }
    return _selectedItem!.totalOnHand;
  }

  double? get _parsedDelta => double.tryParse(_deltaController.text.trim());

  double get _previewOnHand {
    final delta = _parsedDelta ?? 0.0;
    return _currentOnHand + delta;
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    if (_selectedLocation == null || _selectedItem == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Pilih lokasi dan barang terlebih dahulu.'),
        ),
      );
      return;
    }

    final delta = _parsedDelta;
    if (delta == null || delta == 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Perubahan stok (delta) tidak boleh nol.'),
        ),
      );
      return;
    }

    setState(() => _isSubmitting = true);

    final success = await widget.inventoryService.adjustStock(
      itemId: _selectedItem!.id,
      locationId: _selectedLocation!.id,
      delta: delta,
      reason: _reason,
      note: _noteController.text.trim().isNotEmpty
          ? _noteController.text.trim()
          : null,
    );

    if (mounted) {
      setState(() => _isSubmitting = false);
      if (success) {
        Navigator.of(context).pop(true);
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Penyesuaian stok berhasil disimpan.'),
            backgroundColor: Colors.green,
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              widget.inventoryService.errorMessage ??
                  'Gagal melakukan penyesuaian stok.',
            ),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Container(
        constraints: const BoxConstraints(maxWidth: 480),
        padding: const EdgeInsets.all(24),
        child: Form(
          key: _formKey,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Row(
                  children: [
                    const Icon(
                      Icons.tune_rounded,
                      color: Color(0xFF6366F1),
                      size: 24,
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Text(
                        'Penyesuaian Stok',
                        style: theme.textTheme.titleLarge?.copyWith(
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close),
                      onPressed: () => Navigator.of(context).pop(),
                    ),
                  ],
                ),
                const SizedBox(height: 16),

                // Location Selector
                DropdownButtonFormField<InventoryLocationModel>(
                  initialValue: _selectedLocation,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Lokasi Operasional',
                    border: OutlineInputBorder(),
                    prefixIcon: Icon(Icons.location_on_outlined),
                  ),
                  items: widget.inventoryService.locations.map((loc) {
                    return DropdownMenuItem(
                      value: loc,
                      child: Text(
                        '${loc.name} (${loc.code})',
                        overflow: TextOverflow.ellipsis,
                      ),
                    );
                  }).toList(),
                  onChanged: (val) => setState(() => _selectedLocation = val),
                  validator: (v) => v == null ? 'Pilih lokasi' : null,
                ),
                const SizedBox(height: 16),

                // Item Selector
                DropdownButtonFormField<InventoryItemModel>(
                  initialValue: _selectedItem,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Barang Inventory',
                    border: OutlineInputBorder(),
                    prefixIcon: Icon(Icons.inventory_2_outlined),
                  ),
                  items: widget.inventoryService.items.map((item) {
                    return DropdownMenuItem(
                      value: item,
                      child: Text(
                        '${item.name} (${item.baseUnit})',
                        overflow: TextOverflow.ellipsis,
                      ),
                    );
                  }).toList(),
                  onChanged: (val) => setState(() => _selectedItem = val),
                  validator: (v) => v == null ? 'Pilih barang' : null,
                ),
                const SizedBox(height: 16),

                // Current On Hand Card
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: Colors.grey.shade100,
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: Colors.grey.shade300),
                  ),
                  child: Row(
                    mainAxisAlignment: MainAxisAlignment.spaceBetween,
                    children: [
                      const Expanded(
                        child: Text(
                          'Stok Fisik Saat Ini:',
                          style: TextStyle(fontWeight: FontWeight.w500),
                        ),
                      ),
                      Text(
                        '${_currentOnHand.toStringAsFixed(2)} ${_selectedItem?.baseUnit ?? ''}',
                        style: const TextStyle(
                          fontWeight: FontWeight.bold,
                          fontSize: 16,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),

                // Delta Input (+ or -)
                TextFormField(
                  controller: _deltaController,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                    signed: true,
                  ),
                  decoration: const InputDecoration(
                    labelText: 'Jumlah Perubahan (Delta: + atau -)',
                    hintText: 'Contoh: -3 atau 10.5',
                    border: OutlineInputBorder(),
                    prefixIcon: Icon(Icons.exposure),
                    helperText:
                        'Gunakan minus (-) untuk mengurangi stok fisik.',
                  ),
                  onChanged: (_) => setState(() {}),
                  validator: (val) {
                    if (val == null || val.trim().isEmpty) {
                      return 'Masukkan jumlah perubahan';
                    }
                    if (double.tryParse(val.trim()) == null) {
                      return 'Nilai harus berupa angka';
                    }
                    if (double.tryParse(val.trim()) == 0) {
                      return 'Perubahan tidak boleh 0';
                    }
                    return null;
                  },
                ),
                const SizedBox(height: 12),

                // Live Preview
                if (_parsedDelta != null)
                  Container(
                    padding: const EdgeInsets.all(10),
                    decoration: BoxDecoration(
                      color: _previewOnHand < 0
                          ? Colors.red.shade50
                          : Colors.indigo.shade50,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Perkiraan Stok Baru:',
                          style: TextStyle(fontSize: 13),
                        ),
                        Text(
                          '${_previewOnHand.toStringAsFixed(2)} ${_selectedItem?.baseUnit ?? ''}',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            color: _previewOnHand < 0
                                ? Colors.red
                                : Colors.indigo.shade900,
                          ),
                        ),
                      ],
                    ),
                  ),
                const SizedBox(height: 16),

                // Reason Selector
                DropdownButtonFormField<String>(
                  initialValue: _reason,
                  decoration: const InputDecoration(
                    labelText: 'Alasan Penyesuaian',
                    border: OutlineInputBorder(),
                    prefixIcon: Icon(Icons.help_outline),
                  ),
                  items: _reasons.map((r) {
                    return DropdownMenuItem(
                      value: r['value'],
                      child: Text(r['label']!),
                    );
                  }).toList(),
                  onChanged: (val) =>
                      setState(() => _reason = val ?? 'correction'),
                ),
                const SizedBox(height: 16),

                // Note Input
                TextFormField(
                  controller: _noteController,
                  maxLines: 2,
                  decoration: const InputDecoration(
                    labelText: 'Catatan (Opsional)',
                    hintText: 'Keterangan tambahan...',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 24),

                // Submit Button
                ElevatedButton(
                  onPressed: _isSubmitting ? null : _submit,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF6366F1),
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 14),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(8),
                    ),
                  ),
                  child: _isSubmitting
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            color: Colors.white,
                          ),
                        )
                      : const Text(
                          'Konfirmasi Penyesuaian',
                          style: TextStyle(fontWeight: FontWeight.bold),
                        ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
