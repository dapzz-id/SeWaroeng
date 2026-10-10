import 'package:flutter/material.dart';
import '../../core/purchasing/purchasing_models.dart';
import '../../core/purchasing/purchasing_service.dart';

class ReceiveGoodsScreen extends StatefulWidget {
  final PurchaseOrderModel purchaseOrder;
  final PurchasingService purchasingService;

  const ReceiveGoodsScreen({
    super.key,
    required this.purchaseOrder,
    required this.purchasingService,
  });

  @override
  State<ReceiveGoodsScreen> createState() => _ReceiveGoodsScreenState();
}

class _ReceiveGoodsScreenState extends State<ReceiveGoodsScreen> {
  final _notesController = TextEditingController();
  final Map<String, TextEditingController> _quantityControllers = {};
  final Map<String, String?> _quantityErrors = {};
  bool _isSubmitting = false;

  @override
  void initState() {
    super.initState();
    for (final item in widget.purchaseOrder.items) {
      // By default pre-fill with remaining quantity
      _quantityControllers[item.id] = TextEditingController(
        text: item.remainingQuantity > 0
            ? item.remainingQuantity.toString()
            : '0',
      );
    }
  }

  @override
  void dispose() {
    _notesController.dispose();
    for (final c in _quantityControllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  bool _validateQuantities() {
    bool hasValidItem = false;
    _quantityErrors.clear();

    for (final item in widget.purchaseOrder.items) {
      final text = _quantityControllers[item.id]?.text.trim() ?? '0';
      final qty = double.tryParse(text) ?? 0.0;

      if (qty < 0) {
        _quantityErrors[item.id] = 'Jumlah tidak boleh negatif';
      } else if (qty > item.remainingQuantity) {
        _quantityErrors[item.id] =
            'Tidak boleh melebihi sisa pesanan (${item.remainingQuantity})';
      } else if (qty > 0) {
        hasValidItem = true;
      }
    }

    setState(() {});
    return hasValidItem && _quantityErrors.isEmpty;
  }

  Future<void> _submitAndPostReceipt() async {
    if (!_validateQuantities()) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Periksa kembali input penerimaan barang'),
          backgroundColor: Colors.red,
        ),
      );
      return;
    }

    setState(() => _isSubmitting = true);

    try {
      final receiptItems = <Map<String, dynamic>>[];
      for (final item in widget.purchaseOrder.items) {
        final text = _quantityControllers[item.id]?.text.trim() ?? '0';
        final qty = double.tryParse(text) ?? 0.0;
        if (qty > 0) {
          receiptItems.add({
            'purchase_order_item_id': item.id,
            'quantity': qty,
            'unit_cost_minor': item.unitCostMinor,
          });
        }
      }

      if (receiptItems.isEmpty) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Masukkan minimal satu barang yang diterima'),
            backgroundColor: Colors.orange,
          ),
        );
        setState(() => _isSubmitting = false);
        return;
      }

      // Step 1: Create Goods Receipt (draft)
      final grn = await widget.purchasingService.createPurchaseReceipt(
        purchaseOrderId: widget.purchaseOrder.id,
        destinationLocationId: widget.purchaseOrder.destinationLocationId,
        notes: _notesController.text.trim().isEmpty
            ? null
            : _notesController.text.trim(),
        items: receiptItems,
      );

      if (grn == null) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                widget.purchasingService.errorMessage ??
                    'Gagal membuat bukti penerimaan',
              ),
              backgroundColor: Colors.red,
            ),
          );
        }
        setState(() => _isSubmitting = false);
        return;
      }

      // Step 2: Post Goods Receipt atomically into Inventory Ledger
      final postedGrn = await widget.purchasingService.postPurchaseReceipt(
        grn.id,
      );

      if (mounted) {
        if (postedGrn != null) {
          ScaffoldMessenger.of(context).showSnackBar(
            SnackBar(
              content: Text(
                'Penerimaan ${postedGrn.referenceNumber} berhasil dibukukan ke stok inventory!',
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
                    'Gagal membukukan penerimaan ke stok',
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
    final po = widget.purchaseOrder;

    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        title: Text(
          'Terima Barang - ${po.referenceNumber}',
          style: const TextStyle(color: Colors.white),
        ),
        backgroundColor: const Color(0xFF1E293B),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // PO Summary Banner
            Card(
              color: const Color(0xFF1E293B),
              shape: RoundedRectangleBorder(
                borderRadius: BorderRadius.circular(12),
                side: const BorderSide(color: Color(0xFF334155)),
              ),
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text(
                          'Supplier: ${po.supplier?.name ?? po.supplierId}',
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.bold,
                            fontSize: 16,
                          ),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 10,
                            vertical: 4,
                          ),
                          decoration: BoxDecoration(
                            color: Colors.blue.withValues(alpha: 0.2),
                            borderRadius: BorderRadius.circular(8),
                          ),
                          child: Text(
                            'Status PO: ${po.status.toUpperCase()}',
                            style: const TextStyle(
                              color: Colors.blue,
                              fontWeight: FontWeight.bold,
                              fontSize: 12,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 8),
                    Text(
                      'Lokasi Tujuan Penerimaan: ${po.destinationLocationName ?? po.destinationLocationId}',
                      style: const TextStyle(
                        color: Color(0xFF38BDF8),
                        fontSize: 14,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 16),

            const Text(
              'Item Pesanan & Kuantitas Diterima',
              style: TextStyle(
                color: Colors.white,
                fontSize: 16,
                fontWeight: FontWeight.bold,
              ),
            ),
            const SizedBox(height: 8),

            ...po.items.map((item) {
              final errorText = _quantityErrors[item.id];
              return Card(
                color: const Color(0xFF1E293B),
                margin: const EdgeInsets.only(bottom: 12),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(12),
                  side: BorderSide(
                    color: errorText != null
                        ? Colors.red
                        : const Color(0xFF334155),
                  ),
                ),
                child: Padding(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                            child: Text(
                              item.inventoryItemName ??
                                  'Item (${item.inventoryItemId})',
                              style: const TextStyle(
                                color: Colors.white,
                                fontWeight: FontWeight.bold,
                                fontSize: 15,
                              ),
                            ),
                          ),
                          Text(
                            item.formattedUnitCost,
                            style: const TextStyle(
                              color: Color(0xFF38BDF8),
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          _buildStatBox(
                            'Dipesan',
                            item.orderedQuantity.toString(),
                            Colors.blue,
                          ),
                          const SizedBox(width: 8),
                          _buildStatBox(
                            'Sudah Diterima',
                            item.receivedQuantity.toString(),
                            Colors.green,
                          ),
                          const SizedBox(width: 8),
                          _buildStatBox(
                            'Sisa Pesanan',
                            item.remainingQuantity.toString(),
                            Colors.orange,
                          ),
                        ],
                      ),
                      const SizedBox(height: 12),
                      TextField(
                        key: Key('received_qty_field_${item.id}'),
                        controller: _quantityControllers[item.id],
                        keyboardType: const TextInputType.numberWithOptions(
                          decimal: true,
                        ),
                        style: const TextStyle(color: Colors.white),
                        decoration: InputDecoration(
                          labelText:
                              'Jumlah Diterima Saat Ini (${item.baseUnit ?? "unit"}) *',
                          labelStyle: const TextStyle(color: Color(0xFF94A3B8)),
                          errorText: errorText,
                          filled: true,
                          fillColor: const Color(0xFF0F172A),
                          border: OutlineInputBorder(
                            borderRadius: BorderRadius.circular(8),
                            borderSide: BorderSide.none,
                          ),
                        ),
                        onChanged: (_) => _validateQuantities(),
                      ),
                    ],
                  ),
                ),
              );
            }),

            const SizedBox(height: 12),
            TextField(
              controller: _notesController,
              maxLines: 2,
              style: const TextStyle(color: Colors.white),
              decoration: InputDecoration(
                labelText: 'Catatan Penerimaan / Surat Jalan (Opsional)',
                labelStyle: const TextStyle(color: Color(0xFF94A3B8)),
                filled: true,
                fillColor: const Color(0xFF1E293B),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(8),
                  borderSide: BorderSide.none,
                ),
              ),
            ),
            const SizedBox(height: 24),

            ElevatedButton(
              key: const Key('confirm_receive_goods_button'),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF059669),
                padding: const EdgeInsets.symmetric(vertical: 16),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(10),
                ),
              ),
              onPressed: _isSubmitting ? null : _submitAndPostReceipt,
              child: _isSubmitting
                  ? const CircularProgressIndicator(color: Colors.white)
                  : const Text(
                      'Bukukan Penerimaan Barang (GRN)',
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

  Widget _buildStatBox(String label, String value, Color color) {
    return Expanded(
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 8),
        decoration: BoxDecoration(
          color: color.withValues(alpha: 0.1),
          borderRadius: BorderRadius.circular(8),
          border: Border.all(color: color.withValues(alpha: 0.3)),
        ),
        child: Column(
          children: [
            Text(label, style: TextStyle(color: color, fontSize: 11)),
            const SizedBox(height: 2),
            Text(
              value,
              style: TextStyle(
                color: color,
                fontSize: 14,
                fontWeight: FontWeight.bold,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
