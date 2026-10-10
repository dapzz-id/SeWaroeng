import 'package:flutter/material.dart';
import '../../core/purchasing/purchasing_models.dart';
import '../../core/purchasing/purchasing_service.dart';
import 'receive_goods_screen.dart';

class PurchaseOrderDetailScreen extends StatefulWidget {
  final String purchaseOrderId;
  final PurchasingService purchasingService;

  const PurchaseOrderDetailScreen({
    super.key,
    required this.purchaseOrderId,
    required this.purchasingService,
  });

  @override
  State<PurchaseOrderDetailScreen> createState() =>
      _PurchaseOrderDetailScreenState();
}

class _PurchaseOrderDetailScreenState extends State<PurchaseOrderDetailScreen> {
  PurchaseOrderModel? _purchaseOrder;
  bool _isLoading = true;

  @override
  void initState() {
    super.initState();
    _loadDetail();
  }

  Future<void> _loadDetail() async {
    setState(() => _isLoading = true);
    final po = await widget.purchasingService.getPurchaseOrder(
      widget.purchaseOrderId,
    );
    await widget.purchasingService.fetchPurchaseReceipts(
      purchaseOrderId: widget.purchaseOrderId,
    );
    if (mounted) {
      setState(() {
        _purchaseOrder = po;
        _isLoading = false;
      });
    }
  }

  Color _getStatusColor(String status) {
    switch (status) {
      case 'draft':
        return Colors.grey;
      case 'submitted':
        return Colors.blue;
      case 'approved':
        return Colors.amber;
      case 'partially_received':
        return Colors.orange;
      case 'received':
        return Colors.green;
      case 'cancelled':
        return Colors.red;
      default:
        return Colors.blueGrey;
    }
  }

  String _getStatusLabel(String status) {
    switch (status) {
      case 'draft':
        return 'DRAFT';
      case 'submitted':
        return 'MENUNGGU PERSETUJUAN';
      case 'approved':
        return 'DISETUJUI';
      case 'partially_received':
        return 'SEBAGIAN DITERIMA';
      case 'received':
        return 'LENGKAP DITERIMA';
      case 'cancelled':
        return 'DIBATALKAN';
      default:
        return status.toUpperCase();
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_isLoading) {
      return Scaffold(
        backgroundColor: const Color(0xFF0F172A),
        appBar: AppBar(
          title: const Text(
            'Detail Purchase Order',
            style: TextStyle(color: Colors.white),
          ),
          backgroundColor: const Color(0xFF1E293B),
        ),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    final po = _purchaseOrder;
    if (po == null) {
      return Scaffold(
        backgroundColor: const Color(0xFF0F172A),
        appBar: AppBar(
          title: const Text(
            'Detail Purchase Order',
            style: TextStyle(color: Colors.white),
          ),
          backgroundColor: const Color(0xFF1E293B),
        ),
        body: const Center(
          child: Text(
            'Purchase order tidak ditemukan',
            style: TextStyle(color: Colors.white),
          ),
        ),
      );
    }

    final statusColor = _getStatusColor(po.status);

    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        title: Text(
          po.referenceNumber,
          style: const TextStyle(color: Colors.white),
        ),
        backgroundColor: const Color(0xFF1E293B),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white),
            onPressed: _loadDetail,
          ),
        ],
      ),
      bottomNavigationBar: _buildBottomActions(po),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Header Card
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
                          po.referenceNumber,
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 10,
                            vertical: 4,
                          ),
                          decoration: BoxDecoration(
                            color: statusColor.withValues(alpha: 0.2),
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: statusColor),
                          ),
                          child: Text(
                            _getStatusLabel(po.status),
                            style: TextStyle(
                              color: statusColor,
                              fontSize: 12,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const Divider(color: Color(0xFF334155), height: 24),
                    _buildInfoRow(
                      'Supplier',
                      po.supplier?.name ?? po.supplierId,
                    ),
                    _buildInfoRow(
                      'Lokasi Tujuan',
                      po.destinationLocationName ?? po.destinationLocationId,
                    ),
                    _buildInfoRow(
                      'Tanggal Order',
                      po.orderedAt != null
                          ? po.orderedAt!.toLocal().toString().split('.')[0]
                          : '-',
                    ),
                    if (po.expectedAt != null)
                      _buildInfoRow(
                        'Estimasi Tiba',
                        po.expectedAt!.toLocal().toString().split('.')[0],
                      ),
                    if (po.notes != null) _buildInfoRow('Catatan', po.notes!),
                    const Divider(color: Color(0xFF334155), height: 24),
                    Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        const Text(
                          'Total Estimasi Pembelian:',
                          style: TextStyle(
                            color: Color(0xFF94A3B8),
                            fontSize: 14,
                          ),
                        ),
                        Text(
                          po.formattedTotalCost,
                          style: const TextStyle(
                            color: Color(0xFF38BDF8),
                            fontSize: 18,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),
            ),
            const SizedBox(height: 20),

            // Items List
            const Text(
              'Rincian Barang',
              style: TextStyle(
                color: Colors.white,
                fontSize: 16,
                fontWeight: FontWeight.bold,
              ),
            ),
            const SizedBox(height: 8),

            ...po.items.map((item) {
              return Card(
                color: const Color(0xFF1E293B),
                margin: const EdgeInsets.only(bottom: 12),
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
                            '${item.orderedQuantity} ${item.baseUnit ?? ""}',
                            Colors.blue,
                          ),
                          const SizedBox(width: 8),
                          _buildStatBox(
                            'Diterima',
                            '${item.receivedQuantity} ${item.baseUnit ?? ""}',
                            Colors.green,
                          ),
                          const SizedBox(width: 8),
                          _buildStatBox(
                            'Sisa',
                            '${item.remainingQuantity} ${item.baseUnit ?? ""}',
                            Colors.orange,
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              );
            }),

            const SizedBox(height: 20),

            // Receipts History Section
            const Text(
              'Riwayat Penerimaan Barang (GRN)',
              style: TextStyle(
                color: Colors.white,
                fontSize: 16,
                fontWeight: FontWeight.bold,
              ),
            ),
            const SizedBox(height: 8),

            AnimatedBuilder(
              animation: widget.purchasingService,
              builder: (context, _) {
                final receipts = widget.purchasingService.purchaseReceipts;
                if (receipts.isEmpty) {
                  return Container(
                    padding: const EdgeInsets.all(16),
                    decoration: BoxDecoration(
                      color: const Color(0xFF1E293B),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: const Color(0xFF334155)),
                    ),
                    child: const Center(
                      child: Text(
                        'Belum ada penerimaan barang untuk PO ini',
                        style: TextStyle(color: Color(0xFF94A3B8)),
                      ),
                    ),
                  );
                }

                return Column(
                  children: receipts.map((grn) {
                    return Card(
                      color: const Color(0xFF1E293B),
                      margin: const EdgeInsets.only(bottom: 8),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(10),
                        side: const BorderSide(color: Color(0xFF334155)),
                      ),
                      child: ListTile(
                        leading: const Icon(
                          Icons.receipt_long,
                          color: Color(0xFF059669),
                        ),
                        title: Text(
                          grn.referenceNumber,
                          style: const TextStyle(
                            color: Colors.white,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        subtitle: Text(
                          'Diterima: ${grn.receivedAt != null ? grn.receivedAt!.toLocal().toString().split('.')[0] : "-"} | Total: ${grn.totalReceivedQuantity} unit',
                          style: const TextStyle(
                            color: Color(0xFF94A3B8),
                            fontSize: 12,
                          ),
                        ),
                        trailing: Container(
                          padding: const EdgeInsets.symmetric(
                            horizontal: 8,
                            vertical: 2,
                          ),
                          decoration: BoxDecoration(
                            color: Colors.green.withValues(alpha: 0.2),
                            borderRadius: BorderRadius.circular(6),
                          ),
                          child: Text(
                            grn.status.toUpperCase(),
                            style: const TextStyle(
                              color: Colors.green,
                              fontSize: 11,
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ),
                      ),
                    );
                  }).toList(),
                );
              },
            ),
            const SizedBox(height: 40),
          ],
        ),
      ),
    );
  }

  Widget? _buildBottomActions(PurchaseOrderModel po) {
    if (po.isDraft) {
      return Container(
        padding: const EdgeInsets.all(16),
        color: const Color(0xFF1E293B),
        child: Row(
          children: [
            Expanded(
              child: OutlinedButton(
                style: OutlinedButton.styleFrom(
                  foregroundColor: Colors.red,
                  side: const BorderSide(color: Colors.red),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                onPressed: () async {
                  await widget.purchasingService.cancelPurchaseOrder(po.id);
                  _loadDetail();
                },
                child: const Text('Batalkan PO'),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: ElevatedButton(
                key: const Key('submit_po_button'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF0284C7),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                onPressed: () async {
                  await widget.purchasingService.submitPurchaseOrder(po.id);
                  _loadDetail();
                },
                child: const Text(
                  'Ajukan Persetujuan',
                  style: TextStyle(color: Colors.white),
                ),
              ),
            ),
          ],
        ),
      );
    }

    if (po.isSubmitted) {
      return Container(
        padding: const EdgeInsets.all(16),
        color: const Color(0xFF1E293B),
        child: Row(
          children: [
            Expanded(
              child: OutlinedButton(
                style: OutlinedButton.styleFrom(
                  foregroundColor: Colors.red,
                  side: const BorderSide(color: Colors.red),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                onPressed: () async {
                  await widget.purchasingService.cancelPurchaseOrder(po.id);
                  _loadDetail();
                },
                child: const Text('Batalkan PO'),
              ),
            ),
            const SizedBox(width: 12),
            Expanded(
              child: ElevatedButton(
                key: const Key('approve_po_button'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF059669),
                  padding: const EdgeInsets.symmetric(vertical: 14),
                ),
                onPressed: () async {
                  await widget.purchasingService.approvePurchaseOrder(po.id);
                  _loadDetail();
                },
                child: const Text(
                  'Setujui PO (Approve)',
                  style: TextStyle(color: Colors.white),
                ),
              ),
            ),
          ],
        ),
      );
    }

    if (po.canReceive) {
      return Container(
        padding: const EdgeInsets.all(16),
        color: const Color(0xFF1E293B),
        child: ElevatedButton.icon(
          key: const Key('receive_goods_action_button'),
          icon: const Icon(Icons.move_to_inbox, color: Colors.white),
          label: const Text(
            'Terima Barang (Goods Receipt)',
            style: TextStyle(color: Colors.white, fontSize: 16),
          ),
          style: ElevatedButton.styleFrom(
            backgroundColor: const Color(0xFF059669),
            padding: const EdgeInsets.symmetric(vertical: 16),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(10),
            ),
          ),
          onPressed: () async {
            final result = await Navigator.of(context).push<bool>(
              MaterialPageRoute(
                builder: (_) => ReceiveGoodsScreen(
                  purchaseOrder: po,
                  purchasingService: widget.purchasingService,
                ),
              ),
            );
            if (result == true) {
              _loadDetail();
            }
          },
        ),
      );
    }

    return null;
  }

  Widget _buildInfoRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 6),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            label,
            style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
          ),
          Text(
            value,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 13,
              fontWeight: FontWeight.w500,
            ),
          ),
        ],
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
                fontSize: 13,
                fontWeight: FontWeight.bold,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
