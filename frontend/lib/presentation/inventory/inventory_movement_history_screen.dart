import 'package:flutter/material.dart';
import '../../core/inventory/inventory_models.dart';
import '../../core/inventory/inventory_service.dart';

class InventoryMovementHistoryScreen extends StatefulWidget {
  final InventoryService inventoryService;
  final InventoryItemModel? item;

  const InventoryMovementHistoryScreen({
    super.key,
    required this.inventoryService,
    this.item,
  });

  @override
  State<InventoryMovementHistoryScreen> createState() =>
      _InventoryMovementHistoryScreenState();
}

class _InventoryMovementHistoryScreenState
    extends State<InventoryMovementHistoryScreen> {
  List<InventoryMovementModel> _movements = [];
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadMovements();
  }

  Future<void> _loadMovements() async {
    setState(() => _isLoading = true);
    if (widget.item != null) {
      final list = await widget.inventoryService.fetchItemMovements(
        widget.item!.id,
        locationId: widget.inventoryService.selectedLocation?.id,
      );
      if (mounted) {
        setState(() {
          _movements = list;
          _isLoading = false;
        });
      }
    } else {
      setState(() => _isLoading = false);
    }
  }

  Color _getBadgeColor(String type) {
    switch (type) {
      case 'opening_balance':
        return Colors.blue;
      case 'adjustment_in':
      case 'transfer_in':
        return Colors.green;
      case 'adjustment_out':
      case 'transfer_out':
      case 'waste':
      case 'expired':
        return Colors.red;
      case 'stock_opname':
        return Colors.purple;
      case 'reserved':
        return Colors.orange;
      case 'released':
        return Colors.teal;
      default:
        return Colors.grey;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text(
          widget.item != null
              ? 'Riwayat Mutasi: ${widget.item!.name}'
              : 'Riwayat Mutasi Stok',
        ),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadMovements,
          ),
        ],
      ),
      body: _isLoading
          ? const Center(child: CircularProgressIndicator())
          : _movements.isEmpty
          ? const Center(
              child: Text(
                'Belum ada riwayat pergerakan stok untuk barang ini.',
              ),
            )
          : ListView.builder(
              padding: const EdgeInsets.all(16),
              itemCount: _movements.length,
              itemBuilder: (context, index) {
                final m = _movements[index];
                final color = _getBadgeColor(m.movementType);
                final isPositive = m.quantityDelta > 0;

                return Card(
                  elevation: 1,
                  margin: const EdgeInsets.only(bottom: 12),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
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
                              m.movementNumber,
                              style: const TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 14,
                              ),
                            ),
                            Container(
                              padding: const EdgeInsets.symmetric(
                                horizontal: 8,
                                vertical: 3,
                              ),
                              decoration: BoxDecoration(
                                color: color.withValues(alpha: 0.1),
                                borderRadius: BorderRadius.circular(12),
                                border: Border.all(
                                  color: color.withValues(alpha: 0.4),
                                ),
                              ),
                              child: Text(
                                m.typeLabel,
                                style: TextStyle(
                                  color: color,
                                  fontSize: 11,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: 8),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'Lokasi: ${m.locationName ?? "-"}',
                                  style: TextStyle(
                                    color: Colors.grey.shade700,
                                    fontSize: 13,
                                  ),
                                ),
                                if (m.reason != null)
                                  Text(
                                    'Alasan: ${m.reason}',
                                    style: TextStyle(
                                      color: Colors.grey.shade600,
                                      fontSize: 12,
                                    ),
                                  ),
                              ],
                            ),
                            Text(
                              '${isPositive ? "+" : ""}${m.quantityDelta.toStringAsFixed(2)} ${m.unit}',
                              style: TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 16,
                                color: isPositive ? Colors.green : Colors.red,
                              ),
                            ),
                          ],
                        ),
                        const Divider(height: 20),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.spaceBetween,
                          children: [
                            Text(
                              'Sebelum: ${m.beforeOnHand.toStringAsFixed(2)} → Sesudah: ${m.afterOnHand.toStringAsFixed(2)}',
                              style: TextStyle(
                                fontSize: 12,
                                color: Colors.grey.shade600,
                              ),
                            ),
                            if (m.occurredAt != null)
                              Text(
                                '${m.occurredAt!.day}/${m.occurredAt!.month}/${m.occurredAt!.year} ${m.occurredAt!.hour}:${m.occurredAt!.minute.toString().padLeft(2, "0")}',
                                style: TextStyle(
                                  fontSize: 11,
                                  color: Colors.grey.shade500,
                                ),
                              ),
                          ],
                        ),
                        if (m.note != null && m.note!.isNotEmpty) ...[
                          const SizedBox(height: 6),
                          Text(
                            'Catatan: ${m.note}',
                            style: TextStyle(
                              fontSize: 12,
                              color: Colors.grey.shade700,
                              fontStyle: FontStyle.italic,
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                );
              },
            ),
    );
  }
}
