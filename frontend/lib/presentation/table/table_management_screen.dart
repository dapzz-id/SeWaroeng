import 'package:flutter/material.dart';
import '../../core/table/table_models.dart';
import '../../core/table/table_service.dart';
import '../../core/tenant/tenant_service.dart';

import 'floor_plan_editor_screen.dart';

class TableManagementScreen extends StatefulWidget {
  final TableService tableService;
  final TenantService tenantService;

  const TableManagementScreen({
    super.key,
    required this.tableService,
    required this.tenantService,
  });

  @override
  State<TableManagementScreen> createState() => _TableManagementScreenState();
}

class _TableManagementScreenState extends State<TableManagementScreen> {
  String? _selectedOutletId;

  @override
  void initState() {
    super.initState();
    widget.tableService.addListener(_onServiceChanged);
    _selectedOutletId = widget.tenantService.activeOutlet?.id ??
        (widget.tenantService.outlets.isNotEmpty
            ? widget.tenantService.outlets.first.id
            : null);
    _loadTables();
  }

  @override
  void dispose() {
    widget.tableService.removeListener(_onServiceChanged);
    super.dispose();
  }

  void _onServiceChanged() {
    if (mounted) setState(() {});
  }

  void _loadTables() {
    if (_selectedOutletId != null) {
      widget.tableService.fetchTables(outletId: _selectedOutletId);
    }
  }

  @override
  Widget build(BuildContext context) {
    final service = widget.tableService;
    final tables = service.tables;
    final outlets = widget.tenantService.outlets;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Manajemen Meja & Dine-In'),
        actions: [
          IconButton(
            icon: const Icon(Icons.map_outlined),
            tooltip: 'Atur Tata Letak Denah',
            onPressed: () {
              if (_selectedOutletId != null && outlets.isNotEmpty) {
                final outlet = outlets.firstWhere(
                  (o) => o.id == _selectedOutletId,
                  orElse: () => outlets.first,
                );
                Navigator.push(
                  context,
                  MaterialPageRoute(
                    builder: (ctx) => FloorPlanEditorScreen(
                      tableService: widget.tableService,
                      outletId: _selectedOutletId!,
                      outletName: outlet.name,
                    ),
                  ),
                ).then((_) => _loadTables());
              }
            },
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadTables,
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
                const Text('Outlet:', style: TextStyle(fontWeight: FontWeight.bold)),
                const SizedBox(width: 12),
                Expanded(
                  child: DropdownButtonFormField<String>(
                    initialValue: _selectedOutletId,
                    decoration: const InputDecoration(
                      isDense: true,
                      border: OutlineInputBorder(),
                    ),
                    items: outlets
                        .map((o) => DropdownMenuItem(value: o.id, child: Text(o.name)))
                        .toList(),
                    onChanged: (val) {
                      setState(() => _selectedOutletId = val);
                      _loadTables();
                    },
                  ),
                ),
              ],
            ),
          ),
          Expanded(
            child: service.isLoading && tables.isEmpty
                ? const Center(child: CircularProgressIndicator())
                : tables.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.table_restaurant_outlined, size: 64, color: Colors.grey.shade400),
                            const SizedBox(height: 16),
                            const Text(
                              'Belum ada meja di outlet ini',
                              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(height: 8),
                            const Text(
                              'Tambahkan meja untuk mengatur pesanan dine-in & QR order.',
                              style: TextStyle(color: Colors.grey),
                            ),
                          ],
                        ),
                      )
                    : GridView.builder(
                        padding: const EdgeInsets.all(16),
                        gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                          crossAxisCount: 2,
                          crossAxisSpacing: 16,
                          mainAxisSpacing: 16,
                          childAspectRatio: 1.15,
                        ),
                        itemCount: tables.length,
                        itemBuilder: (context, index) {
                          final table = tables[index];
                          return _buildTableCard(table);
                        },
                      ),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _showAddTableDialog,
        icon: const Icon(Icons.add),
        label: const Text('Tambah Meja'),
      ),
    );
  }

  Widget _buildTableCard(TableModel table) {
    Color statusColor;
    String statusLabel;
    IconData statusIcon;

    switch (table.status) {
      case 'occupied':
        statusColor = Colors.red;
        statusLabel = 'Terisi';
        statusIcon = Icons.people;
        break;
      case 'reserved':
        statusColor = Colors.orange;
        statusLabel = 'Dipesan';
        statusIcon = Icons.bookmark;
        break;
      default:
        statusColor = Colors.green;
        statusLabel = 'Tersedia';
        statusIcon = Icons.check_circle_outline;
    }

    return Card(
      elevation: 2,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(16),
        side: BorderSide(color: statusColor.withValues(alpha: 0.5), width: 1.5),
      ),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    table.name,
                    style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: statusColor.withValues(alpha: 0.1),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(statusIcon, size: 12, color: statusColor),
                      const SizedBox(width: 4),
                      Text(
                        statusLabel,
                        style: TextStyle(color: statusColor, fontSize: 11, fontWeight: FontWeight.bold),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 4),
            Text(
              'Kode: ${table.code} • Kapasitas: ${table.capacity} org',
              style: TextStyle(color: Colors.grey.shade600, fontSize: 12),
            ),
            const Spacer(),
            Row(
              mainAxisAlignment: MainAxisAlignment.end,
              children: [
                if (table.isAvailable)
                  FilledButton.tonal(
                    onPressed: () => widget.tableService
                        .checkInTable(table.id, outletId: _selectedOutletId),
                    style: FilledButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 0),
                    ),
                    child: const Text('Check-In', style: TextStyle(fontSize: 12)),
                  ),
                if (table.isOccupied)
                  OutlinedButton(
                    onPressed: () => widget.tableService
                        .releaseTable(table.id, outletId: _selectedOutletId),
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 0),
                    ),
                    child: const Text('Selesai', style: TextStyle(fontSize: 12)),
                  ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  void _showAddTableDialog() {
    if (_selectedOutletId == null) return;
    final codeCtrl = TextEditingController();
    final nameCtrl = TextEditingController();
    final capCtrl = TextEditingController(text: '4');

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Tambah Meja Baru'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: codeCtrl,
              decoration: const InputDecoration(labelText: 'Kode Meja (mis. T-01) *', isDense: true),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: nameCtrl,
              decoration: const InputDecoration(labelText: 'Nama Meja (mis. Meja 1) *', isDense: true),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: capCtrl,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Kapasitas Kursi (orang)', isDense: true),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
          FilledButton(
            onPressed: () async {
              if (codeCtrl.text.trim().isEmpty || nameCtrl.text.trim().isEmpty) return;
              final nav = Navigator.of(ctx);
              final cap = int.tryParse(capCtrl.text.trim()) ?? 4;
              final success = await widget.tableService.createTable(
                outletId: _selectedOutletId!,
                code: codeCtrl.text.trim(),
                name: nameCtrl.text.trim(),
                capacity: cap,
              );
              if (success && mounted) {
                nav.pop();
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('Meja berhasil ditambahkan')),
                );
              }
            },
            child: const Text('Simpan'),
          ),
        ],
      ),
    );
  }
}
