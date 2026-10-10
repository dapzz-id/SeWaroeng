import 'package:flutter/material.dart';
import '../../core/inventory/inventory_models.dart';
import '../../core/inventory/inventory_service.dart';

class StockOpnameScreen extends StatefulWidget {
  final InventoryService inventoryService;

  const StockOpnameScreen({super.key, required this.inventoryService});

  @override
  State<StockOpnameScreen> createState() => _StockOpnameScreenState();
}

class _StockOpnameScreenState extends State<StockOpnameScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      widget.inventoryService.fetchOpnames();
    });
  }

  void _openCreateOpnameDialog() {
    showDialog(
      context: context,
      builder: (ctx) =>
          _CreateOpnameDialog(inventoryService: widget.inventoryService),
    );
  }

  void _showApproveConfirmation(StockOpnameModel opname) {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Setujui Stock Opname'),
        content: Text(
          'Apakah Anda yakin ingin menyetujui sesi opname ${opname.opnameNumber}?\n\n'
          'Sistem akan secara otomatis mencatat pergerakan mutasi stok untuk menyesuaikan fisik dan sistem.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(),
            child: const Text('Batal'),
          ),
          ElevatedButton(
            onPressed: () async {
              Navigator.of(ctx).pop();
              final success = await widget.inventoryService.approveOpname(
                opname.id,
              );
              if (mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: Text(
                      success
                          ? 'Stock Opname berhasil disetujui.'
                          : (widget.inventoryService.errorMessage ??
                                'Gagal menyetujui opname.'),
                    ),
                    backgroundColor: success ? Colors.green : Colors.red,
                  ),
                );
              }
            },
            style: ElevatedButton.styleFrom(backgroundColor: Colors.green),
            child: const Text('Setujui & Perbarui Stok'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Stock Opname (Audit Fisik)'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: () => widget.inventoryService.fetchOpnames(),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openCreateOpnameDialog,
        icon: const Icon(Icons.add_task),
        label: const Text('Mulai Hitung Opname'),
        backgroundColor: const Color(0xFF6366F1),
        foregroundColor: Colors.white,
      ),
      body: ListenableBuilder(
        listenable: widget.inventoryService,
        builder: (context, _) {
          if (widget.inventoryService.isLoading &&
              widget.inventoryService.opnames.isEmpty) {
            return const Center(child: CircularProgressIndicator());
          }

          if (widget.inventoryService.opnames.isEmpty) {
            return Center(
              child: Column(
                mainAxisAlignment: MainAxisAlignment.center,
                children: [
                  Icon(
                    Icons.fact_check_outlined,
                    size: 64,
                    color: Colors.grey.shade400,
                  ),
                  const SizedBox(height: 16),
                  Text(
                    'Belum ada sesi stock opname',
                    style: theme.textTheme.titleMedium?.copyWith(
                      color: Colors.grey.shade600,
                    ),
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    'Klik tombol di bawah untuk memulai penghitungan fisik.',
                  ),
                ],
              ),
            );
          }

          return ListView.builder(
            padding: const EdgeInsets.all(16),
            itemCount: widget.inventoryService.opnames.length,
            itemBuilder: (context, index) {
              final op = widget.inventoryService.opnames[index];
              final isApproved = op.isApproved;

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
                            op.opnameNumber,
                            style: const TextStyle(
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
                              color: isApproved
                                  ? Colors.green.shade50
                                  : Colors.orange.shade50,
                              borderRadius: BorderRadius.circular(16),
                              border: Border.all(
                                color: isApproved
                                    ? Colors.green.shade200
                                    : Colors.orange.shade200,
                              ),
                            ),
                            child: Text(
                              isApproved ? 'DISETUJUI' : 'DRAFT / MENUNGGU',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.bold,
                                color: isApproved
                                    ? Colors.green.shade800
                                    : Colors.orange.shade900,
                              ),
                            ),
                          ),
                        ],
                      ),
                      const SizedBox(height: 8),
                      Row(
                        children: [
                          Icon(
                            Icons.location_on_outlined,
                            size: 16,
                            color: Colors.grey.shade600,
                          ),
                          const SizedBox(width: 4),
                          Text(
                            op.locationName ?? 'Lokasi',
                            style: TextStyle(color: Colors.grey.shade700),
                          ),
                          const SizedBox(width: 16),
                          Icon(
                            Icons.calendar_today_outlined,
                            size: 16,
                            color: Colors.grey.shade600,
                          ),
                          const SizedBox(width: 4),
                          Text(
                            op.createdAt != null
                                ? '${op.createdAt!.day}/${op.createdAt!.month}/${op.createdAt!.year}'
                                : '-',
                            style: TextStyle(color: Colors.grey.shade700),
                          ),
                        ],
                      ),
                      if (op.notes != null && op.notes!.isNotEmpty) ...[
                        const SizedBox(height: 8),
                        Text(
                          'Catatan: ${op.notes}',
                          style: TextStyle(
                            fontSize: 13,
                            color: Colors.grey.shade600,
                            fontStyle: FontStyle.italic,
                          ),
                        ),
                      ],
                      if (!isApproved) ...[
                        const SizedBox(height: 12),
                        const Divider(),
                        Row(
                          mainAxisAlignment: MainAxisAlignment.end,
                          children: [
                            ElevatedButton.icon(
                              onPressed: () => _showApproveConfirmation(op),
                              icon: const Icon(
                                Icons.check_circle_outline,
                                size: 18,
                              ),
                              label: const Text('Review & Setujui Opname'),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: Colors.green,
                                foregroundColor: Colors.white,
                              ),
                            ),
                          ],
                        ),
                      ],
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

class _CreateOpnameDialog extends StatefulWidget {
  final InventoryService inventoryService;

  const _CreateOpnameDialog({required this.inventoryService});

  @override
  State<_CreateOpnameDialog> createState() => _CreateOpnameDialogState();
}

class _CreateOpnameDialogState extends State<_CreateOpnameDialog> {
  late InventoryLocationModel? _selectedLocation;
  final _notesController = TextEditingController();
  final Map<String, TextEditingController> _countControllers = {};
  bool _isSubmitting = false;

  @override
  void initState() {
    super.initState();
    _selectedLocation = widget.inventoryService.selectedLocation;
    for (final item in widget.inventoryService.items) {
      _countControllers[item.id] = TextEditingController();
    }
  }

  @override
  void dispose() {
    _notesController.dispose();
    for (final c in _countControllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submitOpname() async {
    if (_selectedLocation == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Pilih lokasi terlebih dahulu.')),
      );
      return;
    }

    final items = <Map<String, dynamic>>[];
    for (final item in widget.inventoryService.items) {
      final text = _countControllers[item.id]?.text.trim() ?? '';
      if (text.isNotEmpty) {
        final parsed = double.tryParse(text);
        if (parsed != null && parsed >= 0) {
          items.add({
            'inventory_item_id': item.id,
            'physical_quantity': parsed,
            'reason': 'Penghitungan fisik berkala',
          });
        }
      }
    }

    if (items.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Masukkan minimal 1 jumlah fisik barang.'),
        ),
      );
      return;
    }

    setState(() => _isSubmitting = true);

    final opname = await widget.inventoryService.createOpname(
      locationId: _selectedLocation!.id,
      items: items,
      notes: _notesController.text.trim().isNotEmpty
          ? _notesController.text.trim()
          : null,
    );

    if (mounted) {
      setState(() => _isSubmitting = false);
      if (opname != null) {
        Navigator.of(context).pop();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              'Sesi opname ${opname.opnameNumber} berhasil dibuat.',
            ),
            backgroundColor: Colors.green,
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              widget.inventoryService.errorMessage ??
                  'Gagal membuat stock opname.',
            ),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return Dialog(
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      child: Container(
        constraints: const BoxConstraints(maxWidth: 540, maxHeight: 600),
        padding: const EdgeInsets.all(24),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                const Icon(Icons.add_task, color: Color(0xFF6366F1)),
                const SizedBox(width: 12),
                const Text(
                  'Buat Sesi Stock Opname',
                  style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                ),
                const Spacer(),
                IconButton(
                  icon: const Icon(Icons.close),
                  onPressed: () => Navigator.of(context).pop(),
                ),
              ],
            ),
            const SizedBox(height: 16),
            DropdownButtonFormField<InventoryLocationModel>(
              initialValue: _selectedLocation,
              decoration: const InputDecoration(
                labelText: 'Lokasi Penghitungan',
                border: OutlineInputBorder(),
              ),
              items: widget.inventoryService.locations.map((l) {
                return DropdownMenuItem(value: l, child: Text(l.name));
              }).toList(),
              onChanged: (val) => setState(() => _selectedLocation = val),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: _notesController,
              decoration: const InputDecoration(
                labelText: 'Catatan Audit',
                border: OutlineInputBorder(),
              ),
            ),
            const SizedBox(height: 16),
            const Text(
              'Input Hasil Fisik Barang:',
              style: TextStyle(fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 8),
            Expanded(
              child: ListView.builder(
                itemCount: widget.inventoryService.items.length,
                itemBuilder: (ctx, idx) {
                  final item = widget.inventoryService.items[idx];
                  final controller = _countControllers[item.id]!;

                  return Padding(
                    padding: const EdgeInsets.symmetric(vertical: 6),
                    child: Row(
                      children: [
                        Expanded(
                          flex: 3,
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                item.name,
                                style: const TextStyle(
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                              Text(
                                'Sistem: ${item.effectiveOnHand} ${item.baseUnit}',
                                style: TextStyle(
                                  fontSize: 12,
                                  color: Colors.grey.shade600,
                                ),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          flex: 2,
                          child: TextField(
                            controller: controller,
                            keyboardType: const TextInputType.numberWithOptions(
                              decimal: true,
                            ),
                            decoration: InputDecoration(
                              labelText: 'Fisik (${item.baseUnit})',
                              border: const OutlineInputBorder(),
                              contentPadding: const EdgeInsets.symmetric(
                                horizontal: 10,
                                vertical: 8,
                              ),
                            ),
                          ),
                        ),
                      ],
                    ),
                  );
                },
              ),
            ),
            const SizedBox(height: 16),
            ElevatedButton(
              onPressed: _isSubmitting ? null : _submitOpname,
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF6366F1),
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 14),
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
                      'Simpan Sesi Opname',
                      style: TextStyle(fontWeight: FontWeight.bold),
                    ),
            ),
          ],
        ),
      ),
    );
  }
}
