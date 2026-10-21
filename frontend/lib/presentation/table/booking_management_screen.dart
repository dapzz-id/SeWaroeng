import 'package:flutter/material.dart';
import '../../core/table/table_models.dart';
import '../../core/table/table_service.dart';
import '../../core/tenant/tenant_service.dart';
import 'floor_plan_canvas.dart';
import 'floor_plan_editor_screen.dart';

class BookingManagementScreen extends StatefulWidget {
  final TableService tableService;
  final TenantService tenantService;

  const BookingManagementScreen({
    super.key,
    required this.tableService,
    required this.tenantService,
  });

  @override
  State<BookingManagementScreen> createState() =>
      _BookingManagementScreenState();
}

class _BookingManagementScreenState extends State<BookingManagementScreen> {
  String? _selectedOutletId;
  String? _selectedStatus;
  int _activeTabIndex = 0; // 0: Denah Interaktif, 1: Daftar Reservasi
  TableModel? _selectedTableForBooking;

  @override
  void initState() {
    super.initState();
    widget.tableService.addListener(_onServiceChanged);
    _selectedOutletId = widget.tenantService.activeOutlet?.id ??
        (widget.tenantService.outlets.isNotEmpty
            ? widget.tenantService.outlets.first.id
            : null);
    _loadData();
  }

  @override
  void dispose() {
    widget.tableService.removeListener(_onServiceChanged);
    super.dispose();
  }

  void _onServiceChanged() {
    if (mounted) setState(() {});
  }

  void _loadData() {
    if (_selectedOutletId != null) {
      widget.tableService.fetchBookings(
        outletId: _selectedOutletId,
        status: _selectedStatus,
      );
      widget.tableService.fetchFloorPlan(_selectedOutletId!);
    }
  }

  void _openFloorPlanEditor() {
    if (_selectedOutletId != null && widget.tenantService.outlets.isNotEmpty) {
      final outlet = widget.tenantService.outlets.firstWhere(
        (o) => o.id == _selectedOutletId,
        orElse: () => widget.tenantService.outlets.first,
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
      ).then((_) => _loadData());
    }
  }

  @override
  Widget build(BuildContext context) {
    final service = widget.tableService;
    final outlets = widget.tenantService.outlets;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Reservasi & Booking Meja'),
        actions: [
          IconButton(
            icon: const Icon(Icons.tune),
            tooltip: 'Atur Denah Meja (Pemilik)',
            onPressed: _openFloorPlanEditor,
          ),
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadData,
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

          // Outlet & Tab Bar Filter Header
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: Row(
              children: [
                Expanded(
                  flex: 3,
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
                      setState(() {
                        _selectedOutletId = val;
                        _selectedTableForBooking = null;
                      });
                      _loadData();
                    },
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  flex: 4,
                  child: SegmentedButton<int>(
                    segments: const [
                      ButtonSegment(
                        value: 0,
                        icon: Icon(Icons.map_outlined),
                        label: Text('Denah Bioskop'),
                      ),
                      ButtonSegment(
                        value: 1,
                        icon: Icon(Icons.list_alt),
                        label: Text('Daftar Reservasi'),
                      ),
                    ],
                    selected: {_activeTabIndex},
                    onSelectionChanged: (set) {
                      setState(() => _activeTabIndex = set.first);
                    },
                  ),
                ),
              ],
            ),
          ),

          // Main Body: Tab 0 (Cinema-Style Interactive Floor Plan) vs Tab 1 (List of Bookings)
          Expanded(
            child: _activeTabIndex == 0
                ? _buildInteractiveFloorPlanView()
                : _buildBookingsListView(),
          ),
        ],
      ),
      floatingActionButton: _activeTabIndex == 1
          ? FloatingActionButton.extended(
              onPressed: () => _showAddBookingDialog(),
              icon: const Icon(Icons.add_circle_outline),
              label: const Text('Buat Reservasi'),
            )
          : null,
    );
  }

  /// 🗺️ CINEMA-STYLE INTERACTIVE FLOOR PLAN VIEW
  Widget _buildInteractiveFloorPlanView() {
    final service = widget.tableService;
    final floorPlan = service.floorPlan;
    final tables = service.tables;

    if (service.isLoading && tables.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }

    final activeFloorPlan = floorPlan ??
        const FloorPlanData(
          canvasWidth: 960,
          canvasHeight: 640,
          elements: [],
        );

    return Column(
      children: [
        // Cinema Seat Status Legend Header
        Container(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          color: const Color(0xFF1E293B),
          child: SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: [
                _buildLegendItem(const Color(0xFF10B981), 'Tersedia (Bisa Dipilih)'),
                const SizedBox(width: 16),
                _buildLegendItem(const Color(0xFFEF4444), 'Terisi (Dine-In)'),
                const SizedBox(width: 16),
                _buildLegendItem(const Color(0xFFF59E0B), 'Sudah Dipesan'),
                const SizedBox(width: 16),
                _buildLegendItem(const Color(0xFF38BDF8), 'Pilihan Anda'),
                const SizedBox(width: 24),
                OutlinedButton.icon(
                  onPressed: _openFloorPlanEditor,
                  icon: const Icon(Icons.edit, size: 14),
                  label: const Text('Edit Denah'),
                  style: OutlinedButton.styleFrom(
                    visualDensity: VisualDensity.compact,
                    foregroundColor: Colors.amber.shade300,
                    side: BorderSide(color: Colors.amber.shade400),
                  ),
                ),
              ],
            ),
          ),
        ),

        // Zoomable & Pannable Floor Plan Canvas
        Expanded(
          child: FloorPlanCanvas(
            floorPlan: activeFloorPlan,
            tables: tables,
            isEditor: false,
            selectedTable: _selectedTableForBooking,
            onTableSelected: (table) {
              setState(() {
                _selectedTableForBooking = table;
              });
            },
          ),
        ),

        // Cinema Ticket Style Bottom Booking Panel
        if (_selectedTableForBooking != null) _buildSelectedTableBookingBar(),
      ],
    );
  }

  Widget _buildLegendItem(Color color, String label) {
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 14,
          height: 14,
          decoration: BoxDecoration(
            color: color,
            borderRadius: BorderRadius.circular(4),
            boxShadow: [
              BoxShadow(
                color: color.withValues(alpha: 0.5),
                blurRadius: 4,
              ),
            ],
          ),
        ),
        const SizedBox(width: 6),
        Text(
          label,
          style: const TextStyle(
            color: Colors.white,
            fontSize: 12,
            fontWeight: FontWeight.w500,
          ),
        ),
      ],
    );
  }

  /// Bottom action bar when a table is selected in the floor plan (like cinema seat selection)
  Widget _buildSelectedTableBookingBar() {
    final table = _selectedTableForBooking!;
    final isAvailable = table.isAvailable;
    final isIndoor = table.zone.toLowerCase().contains('indoor');

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
      decoration: BoxDecoration(
        color: const Color(0xFF0F172A),
        border: const Border(
          top: BorderSide(color: Color(0xFF38BDF8), width: 2),
        ),
        boxShadow: [
          BoxShadow(
            color: const Color(0xFF38BDF8).withValues(alpha: 0.2),
            blurRadius: 16,
            offset: const Offset(0, -4),
          ),
        ],
      ),
      child: SafeArea(
        child: Row(
          children: [
            Container(
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: const Color(0xFF1E293B),
                borderRadius: BorderRadius.circular(12),
                border: Border.all(color: const Color(0xFF38BDF8)),
              ),
              child: const Icon(Icons.table_restaurant, color: Color(0xFF38BDF8), size: 28),
            ),
            const SizedBox(width: 16),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Row(
                    children: [
                      Text(
                        table.name,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 18,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      const SizedBox(width: 8),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 2),
                        decoration: BoxDecoration(
                          color: isIndoor
                              ? const Color(0xFF0284C7).withValues(alpha: 0.3)
                              : const Color(0xFF059669).withValues(alpha: 0.3),
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(
                            color: isIndoor ? const Color(0xFF38BDF8) : const Color(0xFF34D399),
                          ),
                        ),
                        child: Text(
                          isIndoor ? '❄️ INDOOR (AC)' : '🌿 OUTDOOR',
                          style: TextStyle(
                            fontSize: 10,
                            fontWeight: FontWeight.bold,
                            color: isIndoor ? const Color(0xFFBAE6FD) : const Color(0xFFA7F3D0),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 4),
                  Text(
                    'Kapasitas: ${table.capacity} Orang • Status: ${isAvailable ? "🟢 Siap Dipesan" : (table.isOccupied ? "🔴 Sedang Dipakai" : "🟠 Sudah Ada Reservasi")}',
                    style: TextStyle(
                      color: Colors.grey.shade400,
                      fontSize: 13,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(width: 16),
            FilledButton.icon(
              onPressed: () => _showAddBookingDialog(initialTableId: table.id),
              icon: const Icon(Icons.confirmation_num_outlined),
              label: Text(isAvailable ? 'Booking Meja Ini' : 'Reservasi Meja Ini'),
              style: FilledButton.styleFrom(
                backgroundColor: const Color(0xFF0284C7),
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 14),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// 📋 LIST OF BOOKINGS VIEW
  Widget _buildBookingsListView() {
    final service = widget.tableService;
    final bookings = service.bookings;

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          child: Row(
            children: [
              Expanded(
                child: DropdownButtonFormField<String?>(
                  initialValue: _selectedStatus,
                  decoration: const InputDecoration(
                    labelText: 'Filter Status',
                    isDense: true,
                    border: OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(value: null, child: Text('Semua Status')),
                    DropdownMenuItem(value: 'pending', child: Text('Menunggu')),
                    DropdownMenuItem(value: 'confirmed', child: Text('Dikonfirmasi')),
                    DropdownMenuItem(value: 'seated', child: Text('Check-In')),
                    DropdownMenuItem(value: 'completed', child: Text('Selesai')),
                    DropdownMenuItem(value: 'cancelled', child: Text('Dibatalkan')),
                  ],
                  onChanged: (val) {
                    setState(() => _selectedStatus = val);
                    _loadData();
                  },
                ),
              ),
            ],
          ),
        ),
        Expanded(
          child: service.isLoading && bookings.isEmpty
              ? const Center(child: CircularProgressIndicator())
              : bookings.isEmpty
                  ? Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Icon(Icons.event_seat_outlined, size: 64, color: Colors.grey.shade400),
                          const SizedBox(height: 16),
                          const Text(
                            'Belum ada reservasi',
                            style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                          ),
                          const SizedBox(height: 8),
                          const Text(
                            'Buat reservasi meja baru atau pilih meja langsung dari denah bioskop.',
                            style: TextStyle(color: Colors.grey),
                          ),
                        ],
                      ),
                    )
                  : ListView.separated(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      itemCount: bookings.length,
                      separatorBuilder: (_, _) => const SizedBox(height: 12),
                      itemBuilder: (context, index) {
                        final booking = bookings[index];
                        return _buildBookingCard(booking);
                      },
                    ),
        ),
      ],
    );
  }

  Widget _buildBookingCard(BookingModel booking) {
    Color statusColor;
    String statusLabel;

    switch (booking.status) {
      case 'confirmed':
        statusColor = Colors.blue;
        statusLabel = 'Dikonfirmasi';
        break;
      case 'seated':
        statusColor = Colors.green;
        statusLabel = 'Check-In';
        break;
      case 'completed':
        statusColor = Colors.teal;
        statusLabel = 'Selesai';
        break;
      case 'cancelled':
        statusColor = Colors.red;
        statusLabel = 'Dibatalkan';
        break;
      default:
        statusColor = Colors.orange;
        statusLabel = 'Menunggu';
    }

    return Card(
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        booking.customerName ?? 'Pelanggan Walk-In',
                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                      ),
                      if (booking.customerContact != null)
                        Text(
                          booking.customerContact!,
                          style: TextStyle(color: Colors.grey.shade600, fontSize: 13),
                        ),
                    ],
                  ),
                ),
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
            Row(
              children: [
                Icon(Icons.schedule, size: 16, color: Colors.grey.shade600),
                const SizedBox(width: 6),
                Text(
                  booking.startsAt.length >= 16 ? booking.startsAt.substring(0, 16) : booking.startsAt,
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
                ),
                const SizedBox(width: 16),
                Icon(Icons.people_outline, size: 16, color: Colors.grey.shade600),
                const SizedBox(width: 6),
                Text('${booking.partySize} orang', style: const TextStyle(fontSize: 13)),
                const Spacer(),
                if (booking.status == 'pending' || booking.status == 'confirmed') ...[
                  FilledButton.tonal(
                    onPressed: () => widget.tableService
                        .checkInBooking(booking.id, outletId: _selectedOutletId),
                    style: FilledButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 0),
                    ),
                    child: const Text('Check-In', style: TextStyle(fontSize: 12)),
                  ),
                  const SizedBox(width: 8),
                  OutlinedButton(
                    onPressed: () => widget.tableService
                        .cancelBooking(booking.id, outletId: _selectedOutletId),
                    style: OutlinedButton.styleFrom(
                      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 0),
                    ),
                    child: const Text('Batal', style: TextStyle(fontSize: 12, color: Colors.red)),
                  ),
                ],
              ],
            ),
            if (booking.notes != null && booking.notes!.isNotEmpty) ...[
              const SizedBox(height: 8),
              Text('Catatan: ${booking.notes}', style: TextStyle(color: Colors.grey.shade700, fontSize: 12)),
            ],
          ],
        ),
      ),
    );
  }

  void _showAddBookingDialog({String? initialTableId}) {
    if (_selectedOutletId == null) return;
    final nameCtrl = TextEditingController();
    final contactCtrl = TextEditingController();
    final sizeCtrl = TextEditingController(text: '2');
    final notesCtrl = TextEditingController();
    String? selectedTableId = initialTableId ?? _selectedTableForBooking?.id;
    DateTime selectedDate = DateTime.now().add(const Duration(hours: 1));

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) {
          final chosenTable = selectedTableId != null
              ? widget.tableService.tables.cast<TableModel?>().firstWhere(
                    (t) => t?.id == selectedTableId,
                    orElse: () => null,
                  )
              : null;

          return AlertDialog(
            title: const Row(
              children: [
                Icon(Icons.confirmation_num_outlined, color: Color(0xFF0284C7)),
                SizedBox(width: 8),
                Text('Buat Reservasi Meja'),
              ],
            ),
            content: SingleChildScrollView(
              child: SizedBox(
                width: 400,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (chosenTable != null)
                      Container(
                        margin: const EdgeInsets.only(bottom: 16),
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: const Color(0xFF0284C7).withValues(alpha: 0.15),
                          borderRadius: BorderRadius.circular(10),
                          border: Border.all(color: const Color(0xFF38BDF8)),
                        ),
                        child: Row(
                          children: [
                            const Icon(Icons.check_circle, color: Color(0xFF38BDF8), size: 20),
                            const SizedBox(width: 8),
                            Expanded(
                              child: Text(
                                'Meja Terpilih dari Denah: ${chosenTable.name} (${chosenTable.zone.toUpperCase()} - Kapasitas: ${chosenTable.capacity} Orang)',
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                              ),
                            ),
                          ],
                        ),
                      ),
                    TextField(
                      controller: nameCtrl,
                      decoration: const InputDecoration(labelText: 'Nama Pelanggan *', isDense: true),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: contactCtrl,
                      keyboardType: TextInputType.phone,
                      decoration: const InputDecoration(labelText: 'Nomor WhatsApp / Kontak *', isDense: true),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: sizeCtrl,
                      keyboardType: TextInputType.number,
                      decoration: const InputDecoration(labelText: 'Jumlah Orang *', isDense: true),
                    ),
                    const SizedBox(height: 12),
                    DropdownButtonFormField<String?>(
                      initialValue: selectedTableId,
                      decoration: const InputDecoration(labelText: 'Pilih Meja', isDense: true),
                      items: [
                        const DropdownMenuItem(value: null, child: Text('Pilih Meja Nanti (Walk-in)')),
                        ...widget.tableService.tables.map(
                          (t) => DropdownMenuItem(
                            value: t.id,
                            child: Text('${t.name} (${t.zone.toUpperCase()} - Kapasitas: ${t.capacity})'),
                          ),
                        ),
                      ],
                      onChanged: (val) => setDialogState(() => selectedTableId = val),
                    ),
                    const SizedBox(height: 16),
                    Row(
                      children: [
                        const Icon(Icons.access_time, size: 20),
                        const SizedBox(width: 8),
                        Text(
                          'Waktu: ${selectedDate.toIso8601String().substring(0, 16).replaceAll("T", " ")}',
                          style: const TextStyle(fontWeight: FontWeight.bold),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      controller: notesCtrl,
                      maxLines: 2,
                      decoration: const InputDecoration(
                        labelText: 'Catatan Khusus (meja smoking, baby chair, dsb)',
                        isDense: true,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
              FilledButton(
                onPressed: () async {
                  if (nameCtrl.text.trim().isEmpty || contactCtrl.text.trim().isEmpty) return;
                  final nav = Navigator.of(ctx);
                  final size = int.tryParse(sizeCtrl.text.trim()) ?? 2;
                  final success = await widget.tableService.createBooking(
                    outletId: _selectedOutletId!,
                    tableId: selectedTableId,
                    customerName: nameCtrl.text.trim(),
                    customerContact: contactCtrl.text.trim(),
                    partySize: size,
                    startsAt: selectedDate.toIso8601String(),
                    notes: notesCtrl.text.trim().isNotEmpty ? notesCtrl.text.trim() : null,
                  );
                  if (success && mounted) {
                    nav.pop();
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text('🎉 Reservasi berhasil dibuat!'),
                        backgroundColor: Colors.green,
                      ),
                    );
                    _loadData();
                  }
                },
                child: const Text('Simpan Reservasi'),
              ),
            ],
          );
        },
      ),
    );
  }
}
