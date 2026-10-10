import 'package:flutter/material.dart';
import '../../core/inventory/inventory_service.dart';
import '../../core/purchasing/purchasing_service.dart';
import 'purchase_order_create_screen.dart';
import 'purchase_order_detail_screen.dart';
import 'purchase_receipt_history_screen.dart';
import 'supplier_list_screen.dart';

class PurchaseOrderListScreen extends StatefulWidget {
  final PurchasingService purchasingService;
  final InventoryService inventoryService;

  const PurchaseOrderListScreen({
    super.key,
    required this.purchasingService,
    required this.inventoryService,
  });

  @override
  State<PurchaseOrderListScreen> createState() =>
      _PurchaseOrderListScreenState();
}

class _PurchaseOrderListScreenState extends State<PurchaseOrderListScreen> {
  String _selectedStatus = 'all';

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadOrders();
    });
  }

  void _loadOrders() {
    widget.purchasingService.fetchPurchaseOrders(
      status: _selectedStatus == 'all' ? null : _selectedStatus,
    );
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
        return 'PENGAJUAN';
      case 'approved':
        return 'DISETUJUI';
      case 'partially_received':
        return 'SEBAGIAN';
      case 'received':
        return 'LENGKAP';
      case 'cancelled':
        return 'BATAL';
      default:
        return status.toUpperCase();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        title: const Text(
          'Pengadaan & Pembelian (PO)',
          style: TextStyle(color: Colors.white),
        ),
        backgroundColor: const Color(0xFF1E293B),
        actions: [
          IconButton(
            key: const Key('open_suppliers_action'),
            icon: const Icon(Icons.people_outline, color: Color(0xFF38BDF8)),
            tooltip: 'Kelola Supplier',
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (_) => SupplierListScreen(
                    purchasingService: widget.purchasingService,
                  ),
                ),
              );
            },
          ),
          IconButton(
            key: const Key('open_receipts_history_action'),
            icon: const Icon(Icons.receipt_long, color: Color(0xFF34D399)),
            tooltip: 'Riwayat Penerimaan (GRN)',
            onPressed: () {
              Navigator.of(context).push(
                MaterialPageRoute(
                  builder: (_) => PurchaseReceiptHistoryScreen(
                    purchasingService: widget.purchasingService,
                  ),
                ),
              );
            },
          ),
          IconButton(
            key: const Key('refresh_po_button'),
            icon: const Icon(Icons.refresh, color: Colors.white),
            onPressed: _loadOrders,
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('create_purchase_order_fab'),
        backgroundColor: const Color(0xFF0284C7),
        icon: const Icon(Icons.add_shopping_cart, color: Colors.white),
        label: const Text(
          'Buat PO Baru',
          style: TextStyle(color: Colors.white),
        ),
        onPressed: () async {
          final created = await Navigator.of(context).push<bool>(
            MaterialPageRoute(
              builder: (_) => PurchaseOrderCreateScreen(
                purchasingService: widget.purchasingService,
                inventoryService: widget.inventoryService,
              ),
            ),
          );
          if (created == true) {
            _loadOrders();
          }
        },
      ),
      body: Column(
        children: [
          // Filter Chips
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
            child: Row(
              children: [
                _buildFilterChip('all', 'Semua Status'),
                const SizedBox(width: 8),
                _buildFilterChip('draft', 'Draft'),
                const SizedBox(width: 8),
                _buildFilterChip('submitted', 'Pengajuan'),
                const SizedBox(width: 8),
                _buildFilterChip('approved', 'Disetujui'),
                const SizedBox(width: 8),
                _buildFilterChip('partially_received', 'Sebagian Diterima'),
                const SizedBox(width: 8),
                _buildFilterChip('received', 'Lengkap'),
                const SizedBox(width: 8),
                _buildFilterChip('cancelled', 'Dibatalkan'),
              ],
            ),
          ),

          // PO List
          Expanded(
            child: AnimatedBuilder(
              animation: widget.purchasingService,
              builder: (context, _) {
                if (widget.purchasingService.isLoading &&
                    widget.purchasingService.purchaseOrders.isEmpty) {
                  return const Center(child: CircularProgressIndicator());
                }

                final orders = widget.purchasingService.purchaseOrders;
                if (orders.isEmpty) {
                  return const Center(
                    child: Text(
                      'Tidak ada Purchase Order yang ditemukan',
                      style: TextStyle(color: Color(0xFF94A3B8), fontSize: 16),
                    ),
                  );
                }

                return ListView.separated(
                  padding: const EdgeInsets.all(16),
                  itemCount: orders.length,
                  separatorBuilder: (context, index) =>
                      const SizedBox(height: 12),
                  itemBuilder: (context, index) {
                    final po = orders[index];
                    final statusColor = _getStatusColor(po.status);

                    return Card(
                      color: const Color(0xFF1E293B),
                      shape: RoundedRectangleBorder(
                        borderRadius: BorderRadius.circular(12),
                        side: const BorderSide(color: Color(0xFF334155)),
                      ),
                      child: InkWell(
                        borderRadius: BorderRadius.circular(12),
                        onTap: () async {
                          await Navigator.of(context).push(
                            MaterialPageRoute(
                              builder: (_) => PurchaseOrderDetailScreen(
                                purchaseOrderId: po.id,
                                purchasingService: widget.purchasingService,
                              ),
                            ),
                          );
                          _loadOrders();
                        },
                        child: Padding(
                          padding: const EdgeInsets.all(16),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                mainAxisAlignment:
                                    MainAxisAlignment.spaceBetween,
                                children: [
                                  Text(
                                    po.referenceNumber,
                                    style: const TextStyle(
                                      color: Colors.white,
                                      fontWeight: FontWeight.bold,
                                      fontSize: 16,
                                    ),
                                  ),
                                  Container(
                                    padding: const EdgeInsets.symmetric(
                                      horizontal: 8,
                                      vertical: 2,
                                    ),
                                    decoration: BoxDecoration(
                                      color: statusColor.withValues(alpha: 0.2),
                                      borderRadius: BorderRadius.circular(6),
                                      border: Border.all(color: statusColor),
                                    ),
                                    child: Text(
                                      _getStatusLabel(po.status),
                                      style: TextStyle(
                                        color: statusColor,
                                        fontWeight: FontWeight.bold,
                                        fontSize: 11,
                                      ),
                                    ),
                                  ),
                                ],
                              ),
                              const SizedBox(height: 8),
                              Text(
                                'Supplier: ${po.supplier?.name ?? po.supplierId}',
                                style: const TextStyle(
                                  color: Color(0xFF38BDF8),
                                  fontSize: 14,
                                ),
                              ),
                              if (po.destinationLocationName != null) ...[
                                const SizedBox(height: 2),
                                Text(
                                  'Tujuan: ${po.destinationLocationName!}',
                                  style: const TextStyle(
                                    color: Color(0xFF94A3B8),
                                    fontSize: 12,
                                  ),
                                ),
                              ],
                              const SizedBox(height: 8),
                              Row(
                                mainAxisAlignment:
                                    MainAxisAlignment.spaceBetween,
                                children: [
                                  Text(
                                    'Diterima: ${po.totalReceivedQuantity} / ${po.totalOrderedQuantity} unit',
                                    style: const TextStyle(
                                      color: Color(0xFFCBD5E1),
                                      fontSize: 13,
                                    ),
                                  ),
                                  Text(
                                    po.formattedTotalCost,
                                    style: const TextStyle(
                                      color: Colors.white,
                                      fontWeight: FontWeight.bold,
                                      fontSize: 14,
                                    ),
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      ),
                    );
                  },
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildFilterChip(String status, String label) {
    final isSelected = _selectedStatus == status;
    return ChoiceChip(
      label: Text(label),
      selected: isSelected,
      selectedColor: const Color(0xFF0284C7),
      backgroundColor: const Color(0xFF1E293B),
      labelStyle: TextStyle(
        color: isSelected ? Colors.white : const Color(0xFF94A3B8),
        fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
      ),
      onSelected: (selected) {
        if (selected) {
          setState(() => _selectedStatus = status);
          _loadOrders();
        }
      },
    );
  }
}
