import 'package:flutter/material.dart';
import '../../core/order/order_models.dart';
import '../../core/order/order_service.dart';
import '../../core/tenant/tenant_service.dart';

class OrderManagementScreen extends StatefulWidget {
  final OrderService orderService;
  final TenantService tenantService;

  const OrderManagementScreen({
    super.key,
    required this.orderService,
    required this.tenantService,
  });

  @override
  State<OrderManagementScreen> createState() => _OrderManagementScreenState();
}

class _OrderManagementScreenState extends State<OrderManagementScreen> {
  String? _selectedOutletId;
  String? _selectedStatus;

  @override
  void initState() {
    super.initState();
    widget.orderService.addListener(_onServiceChanged);
    _selectedOutletId = widget.tenantService.activeOutlet?.id ??
        (widget.tenantService.outlets.isNotEmpty
            ? widget.tenantService.outlets.first.id
            : null);
    _loadOrders();
  }

  @override
  void dispose() {
    widget.orderService.removeListener(_onServiceChanged);
    super.dispose();
  }

  void _onServiceChanged() {
    if (mounted) setState(() {});
  }

  void _loadOrders() {
    if (_selectedOutletId != null) {
      widget.orderService.fetchOrders(
        outletId: _selectedOutletId,
        status: _selectedStatus,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final service = widget.orderService;
    final orders = service.orders;
    final outlets = widget.tenantService.outlets;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Pesanan Online & QR Meja'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadOrders,
            tooltip: 'Segarkan',
          ),
        ],
      ),
      body: Column(
        children: [
          if (service.errorMessage != null)
            Container(
              color: Colors.red.shade50,
              padding: const EdgeInsets.all(12),
              child: Row(
                children: [
                  Icon(Icons.error_outline, color: Colors.red.shade700),
                  const SizedBox(width: 8),
                  Expanded(
                    child: Text(
                      service.errorMessage!,
                      style: TextStyle(color: Colors.red.shade900),
                    ),
                  ),
                ],
              ),
            ),
          Padding(
            padding: const EdgeInsets.all(16),
            child: Row(
              children: [
                Expanded(
                  child: DropdownButtonFormField<String>(
                    initialValue: _selectedOutletId,
                    decoration: const InputDecoration(
                      labelText: 'Outlet',
                      isDense: true,
                      border: OutlineInputBorder(),
                    ),
                    items: outlets
                        .map((o) => DropdownMenuItem(value: o.id, child: Text(o.name)))
                        .toList(),
                    onChanged: (val) {
                      setState(() => _selectedOutletId = val);
                      _loadOrders();
                    },
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: DropdownButtonFormField<String?>(
                    initialValue: _selectedStatus,
                    decoration: const InputDecoration(
                      labelText: 'Status Pesanan',
                      isDense: true,
                      border: OutlineInputBorder(),
                    ),
                    items: const [
                      DropdownMenuItem(value: null, child: Text('Semua Status')),
                      DropdownMenuItem(value: 'pending', child: Text('Menunggu Konfirmasi')),
                      DropdownMenuItem(value: 'confirmed', child: Text('Dikonfirmasi')),
                      DropdownMenuItem(value: 'preparing', child: Text('Sedang Dimasak')),
                      DropdownMenuItem(value: 'ready', child: Text('Siap Disajikan')),
                      DropdownMenuItem(value: 'completed', child: Text('Selesai')),
                      DropdownMenuItem(value: 'cancelled', child: Text('Dibatalkan')),
                    ],
                    onChanged: (val) {
                      setState(() => _selectedStatus = val);
                      _loadOrders();
                    },
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: service.isLoading && orders.isEmpty
                ? const Center(child: CircularProgressIndicator())
                : orders.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.receipt_long_outlined, size: 64, color: Colors.grey.shade400),
                            const SizedBox(height: 16),
                            const Text(
                              'Belum ada pesanan aktif',
                              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(height: 8),
                            const Text(
                              'Pesanan pelanggan dari QR Code meja atau online akan muncul di sini.',
                              style: TextStyle(color: Colors.grey),
                            ),
                          ],
                        ),
                      )
                    : ListView.separated(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        itemCount: orders.length,
                        separatorBuilder: (_, _) => const SizedBox(height: 12),
                        itemBuilder: (context, index) {
                          final order = orders[index];
                          return _buildOrderCard(order);
                        },
                      ),
          ),
        ],
      ),
    );
  }

  Widget _buildOrderCard(OrderModel order) {
    Color statusColor;
    String statusLabel;

    switch (order.status) {
      case 'confirmed':
        statusColor = Colors.blue;
        statusLabel = 'Dikonfirmasi';
        break;
      case 'preparing':
        statusColor = Colors.orange;
        statusLabel = 'Dimasak';
        break;
      case 'ready':
        statusColor = Colors.teal;
        statusLabel = 'Siap Saji';
        break;
      case 'completed':
        statusColor = Colors.green;
        statusLabel = 'Selesai';
        break;
      case 'cancelled':
        statusColor = Colors.red;
        statusLabel = 'Dibatalkan';
        break;
      default:
        statusColor = Colors.purple;
        statusLabel = 'Menunggu';
    }

    final formattedTotal = 'Rp ${(order.totalMinor).toString().replaceAllMapped(RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'), (m) => '${m[1]}.')}';

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Text(
                  '#${order.orderNumber}',
                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                ),
                if (order.tableName != null) ...[
                  const SizedBox(width: 8),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                    decoration: BoxDecoration(
                      color: Colors.grey.shade200,
                      borderRadius: BorderRadius.circular(8),
                    ),
                    child: Text(
                      order.tableName!,
                      style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
                const Spacer(),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusColor.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: statusColor),
                  ),
                  child: Text(
                    statusLabel,
                    style: TextStyle(color: statusColor, fontSize: 12, fontWeight: FontWeight.bold),
                  ),
                ),
              ],
            ),
            const Divider(height: 20),
            if (order.items.isNotEmpty) ...[
              ...order.items.map((item) => Padding(
                    padding: const EdgeInsets.symmetric(vertical: 2),
                    child: Row(
                      children: [
                        Text(
                          '${item.quantity.toInt()}x',
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            item.variantName != null
                                ? '${item.productName} (${item.variantName})'
                                : item.productName,
                          ),
                        ),
                      ],
                    ),
                  )),
              const SizedBox(height: 8),
            ],
            if (order.customerNotes != null && order.customerNotes!.isNotEmpty)
              Container(
                margin: const EdgeInsets.only(bottom: 8),
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: Colors.amber.shade50,
                  borderRadius: BorderRadius.circular(8),
                ),
                child: Row(
                  children: [
                    Icon(Icons.notes, size: 16, color: Colors.amber.shade900),
                    const SizedBox(width: 6),
                    Expanded(
                      child: Text(
                        'Catatan: ${order.customerNotes}',
                        style: TextStyle(fontSize: 12, color: Colors.amber.shade900),
                      ),
                    ),
                  ],
                ),
              ),
            Row(
              children: [
                Text(
                  formattedTotal,
                  style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: Colors.teal),
                ),
                const Spacer(),
                if (order.status == 'pending') ...[
                  FilledButton(
                    onPressed: () => widget.orderService.updateOrderStatus(
                      order.id,
                      'confirmed',
                      outletId: _selectedOutletId,
                    ),
                    style: FilledButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 0),
                    ),
                    child: const Text('Terima', style: TextStyle(fontSize: 12)),
                  ),
                  const SizedBox(width: 8),
                  OutlinedButton(
                    onPressed: () => widget.orderService.updateOrderStatus(
                      order.id,
                      'cancelled',
                      outletId: _selectedOutletId,
                    ),
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 0),
                    ),
                    child: const Text('Tolak', style: TextStyle(fontSize: 12, color: Colors.red)),
                  ),
                ],
                if (order.status == 'confirmed')
                  FilledButton.tonal(
                    onPressed: () => widget.orderService.updateOrderStatus(
                      order.id,
                      'preparing',
                      outletId: _selectedOutletId,
                    ),
                    child: const Text('Mulai Masak', style: TextStyle(fontSize: 12)),
                  ),
                if (order.status == 'preparing')
                  FilledButton.tonal(
                    onPressed: () => widget.orderService.updateOrderStatus(
                      order.id,
                      'ready',
                      outletId: _selectedOutletId,
                    ),
                    child: const Text('Siap Saji', style: TextStyle(fontSize: 12)),
                  ),
                if (order.status == 'ready')
                  FilledButton(
                    onPressed: () => widget.orderService.convertToSale(
                      outletId: _selectedOutletId!,
                      orderIds: [order.id],
                    ),
                    style: FilledButton.styleFrom(
                      backgroundColor: Colors.teal,
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 0),
                    ),
                    child: const Text('Proses ke Kasir (Bayar)', style: TextStyle(fontSize: 12)),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
