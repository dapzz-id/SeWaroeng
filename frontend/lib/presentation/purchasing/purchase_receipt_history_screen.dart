import 'package:flutter/material.dart';
import '../../core/purchasing/purchasing_service.dart';

class PurchaseReceiptHistoryScreen extends StatefulWidget {
  final PurchasingService purchasingService;

  const PurchaseReceiptHistoryScreen({
    super.key,
    required this.purchasingService,
  });

  @override
  State<PurchaseReceiptHistoryScreen> createState() =>
      _PurchaseReceiptHistoryScreenState();
}

class _PurchaseReceiptHistoryScreenState
    extends State<PurchaseReceiptHistoryScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      widget.purchasingService.fetchPurchaseReceipts();
    });
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        title: const Text(
          'Riwayat Penerimaan Barang (GRN)',
          style: TextStyle(color: Colors.white),
        ),
        backgroundColor: const Color(0xFF1E293B),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh, color: Colors.white),
            onPressed: () => widget.purchasingService.fetchPurchaseReceipts(),
          ),
        ],
      ),
      body: AnimatedBuilder(
        animation: widget.purchasingService,
        builder: (context, _) {
          if (widget.purchasingService.isLoading &&
              widget.purchasingService.purchaseReceipts.isEmpty) {
            return const Center(child: CircularProgressIndicator());
          }

          final receipts = widget.purchasingService.purchaseReceipts;
          if (receipts.isEmpty) {
            return const Center(
              child: Text(
                'Belum ada riwayat penerimaan barang',
                style: TextStyle(color: Color(0xFF94A3B8), fontSize: 16),
              ),
            );
          }

          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: receipts.length,
            separatorBuilder: (context, index) => const SizedBox(height: 12),
            itemBuilder: (context, index) {
              final grn = receipts[index];
              return Card(
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
                            grn.referenceNumber,
                            style: const TextStyle(
                              color: Colors.white,
                              fontWeight: FontWeight.bold,
                              fontSize: 16,
                            ),
                          ),
                          Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 8,
                              vertical: 3,
                            ),
                            decoration: BoxDecoration(
                              color: Colors.green.withValues(alpha: 0.2),
                              borderRadius: BorderRadius.circular(6),
                            ),
                            child: Text(
                              grn.status.toUpperCase(),
                              style: const TextStyle(
                                color: Colors.green,
                                fontWeight: FontWeight.bold,
                                fontSize: 12,
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Text(
                        'PO: ${grn.purchaseOrderReference ?? grn.purchaseOrderId}',
                        style: const TextStyle(
                          color: Color(0xFF38BDF8),
                          fontSize: 13,
                        ),
                      ),
                      if (grn.destinationLocationName != null) ...[
                        const SizedBox(height: 2),
                        Text(
                          'Lokasi: ${grn.destinationLocationName!}',
                          style: const TextStyle(
                            color: Color(0xFF94A3B8),
                            fontSize: 13,
                          ),
                        ),
                      ],
                      if (grn.receivedAt != null) ...[
                        const SizedBox(height: 2),
                        Text(
                          'Diterima Pada: ${grn.receivedAt!.toLocal().toString().split('.')[0]}',
                          style: const TextStyle(
                            color: Color(0xFF64748B),
                            fontSize: 12,
                          ),
                        ),
                      ],
                      if (grn.notes != null) ...[
                        const SizedBox(height: 4),
                        Text(
                          'Catatan: ${grn.notes!}',
                          style: const TextStyle(
                            color: Color(0xFFCBD5E1),
                            fontSize: 13,
                          ),
                        ),
                      ],
                      const Divider(color: Color(0xFF334155), height: 16),
                      Text(
                        'Total Kuantitas Masuk: ${grn.totalReceivedQuantity} unit',
                        style: const TextStyle(
                          color: Colors.white,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                    ],
                  ),
                ),
              );
            },
          );
        },
      ),
    );
  }
}
