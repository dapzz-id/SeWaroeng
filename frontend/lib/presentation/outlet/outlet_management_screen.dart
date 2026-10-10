import 'package:flutter/material.dart';
import '../../core/tenant/outlet_model.dart';
import '../../core/tenant/tenant_service.dart';

class OutletManagementScreen extends StatefulWidget {
  final TenantService tenantService;

  const OutletManagementScreen({super.key, required this.tenantService});

  @override
  State<OutletManagementScreen> createState() => _OutletManagementScreenState();
}

class _OutletManagementScreenState extends State<OutletManagementScreen> {
  @override
  void initState() {
    super.initState();
    // Refresh outlets on load
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final tenantId = widget.tenantService.activeTenant?.id;
      if (tenantId != null && tenantId.isNotEmpty) {
        widget.tenantService.fetchOutlets(tenantId);
      }
    });
  }

  void _openOutletForm([OutletModel? outlet]) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      backgroundColor: const Color(0xFF1E293B),
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (_) =>
          _OutletFormSheet(tenantService: widget.tenantService, outlet: outlet),
    );
  }

  Future<void> _toggleStatus(OutletModel outlet) async {
    final newStatus = outlet.isActive ? 'inactive' : 'active';
    final success = await widget.tenantService.toggleOutletStatus(
      outletId: outlet.id,
      status: newStatus,
    );
    if (success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            newStatus == 'active'
                ? 'Outlet "${outlet.name}" berhasil diaktifkan.'
                : 'Outlet "${outlet.name}" telah dinonaktifkan.',
          ),
          backgroundColor: newStatus == 'active'
              ? const Color(0xFF059669)
              : const Color(0xFFD97706),
        ),
      );
    }
  }

  Future<void> _selectActiveOutlet(OutletModel outlet) async {
    if (!outlet.isActive) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Outlet nonaktif tidak dapat dijadikan outlet operasional.',
          ),
          backgroundColor: Color(0xFFEF4444),
        ),
      );
      return;
    }

    final success = await widget.tenantService.setActiveOutlet(outlet);
    if (success && mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Outlet aktif diubah ke "${outlet.name}".'),
          backgroundColor: const Color(0xFF059669),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: const Color(0xFF0F172A),
      appBar: AppBar(
        title: const Text(
          'Kelola Outlet',
          style: TextStyle(color: Colors.white),
        ),
        backgroundColor: const Color(0xFF1E293B),
        elevation: 0,
        iconTheme: const IconThemeData(color: Colors.white),
        actions: [
          IconButton(
            key: const Key('refresh_outlets_button'),
            icon: const Icon(Icons.refresh),
            onPressed: () {
              final tenantId = widget.tenantService.activeTenant?.id;
              if (tenantId != null) {
                widget.tenantService.fetchOutlets(tenantId);
              }
            },
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        key: const Key('add_outlet_fab'),
        backgroundColor: const Color(0xFF0284C7),
        icon: const Icon(Icons.add, color: Colors.white),
        label: const Text(
          'Tambah Outlet',
          style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
        ),
        onPressed: () => _openOutletForm(),
      ),
      body: SafeArea(
        child: AnimatedBuilder(
          animation: widget.tenantService,
          builder: (context, _) {
            final isLoading = widget.tenantService.isLoading;
            final errorMessage = widget.tenantService.errorMessage;
            final outlets = widget.tenantService.outlets;
            final activeOutlet = widget.tenantService.activeOutlet;

            if (isLoading && outlets.isEmpty) {
              return const Center(
                child: CircularProgressIndicator(
                  valueColor: AlwaysStoppedAnimation<Color>(Color(0xFF38BDF8)),
                ),
              );
            }

            return RefreshIndicator(
              onRefresh: () async {
                final tenantId = widget.tenantService.activeTenant?.id;
                if (tenantId != null) {
                  await widget.tenantService.fetchOutlets(tenantId);
                }
              },
              child: ListView(
                padding: const EdgeInsets.fromLTRB(16, 16, 16, 80),
                children: [
                  if (errorMessage != null) ...[
                    Container(
                      key: const Key('outlets_error_banner'),
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: const Color(0xFF7F1D1D),
                        borderRadius: BorderRadius.circular(8),
                        border: Border.all(color: const Color(0xFFDC2626)),
                      ),
                      child: Row(
                        children: [
                          const Icon(
                            Icons.error_outline,
                            color: Colors.white,
                            size: 20,
                          ),
                          const SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              errorMessage,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 13,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                  ],

                  if (outlets.isEmpty)
                    Center(
                      child: Padding(
                        padding: const EdgeInsets.symmetric(vertical: 40),
                        child: Column(
                          children: [
                            const Icon(
                              Icons.store_mall_directory_outlined,
                              size: 64,
                              color: Color(0xFF64748B),
                            ),
                            const SizedBox(height: 16),
                            const Text(
                              'Belum ada outlet terdaftar.',
                              style: TextStyle(
                                color: Colors.white,
                                fontSize: 16,
                                fontWeight: FontWeight.bold,
                              ),
                            ),
                            const SizedBox(height: 8),
                            const Text(
                              'Tambahkan outlet untuk mulai mengatur operasional warung / toko Anda.',
                              textAlign: TextAlign.center,
                              style: TextStyle(
                                color: Color(0xFF94A3B8),
                                fontSize: 13,
                              ),
                            ),
                            const SizedBox(height: 20),
                            ElevatedButton.icon(
                              key: const Key('empty_add_outlet_button'),
                              onPressed: () => _openOutletForm(),
                              icon: const Icon(Icons.add),
                              label: const Text('Tambah Outlet Pertama'),
                              style: ElevatedButton.styleFrom(
                                backgroundColor: const Color(0xFF0284C7),
                                foregroundColor: Colors.white,
                              ),
                            ),
                          ],
                        ),
                      ),
                    )
                  else
                    ...outlets.map((outlet) {
                      final isCurrentActive = activeOutlet?.id == outlet.id;

                      return Card(
                        key: Key('outlet_card_${outlet.id}'),
                        margin: const EdgeInsets.only(bottom: 12),
                        color: const Color(0xFF1E293B),
                        shape: RoundedRectangleBorder(
                          borderRadius: BorderRadius.circular(12),
                          side: BorderSide(
                            color: isCurrentActive
                                ? const Color(0xFF0284C7)
                                : const Color(0xFF334155),
                            width: isCurrentActive ? 2 : 1,
                          ),
                        ),
                        child: Padding(
                          padding: const EdgeInsets.all(16.0),
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Row(
                                children: [
                                  CircleAvatar(
                                    backgroundColor: outlet.isActive
                                        ? const Color(0xFF065F46)
                                        : const Color(0xFF475569),
                                    child: Icon(
                                      Icons.storefront,
                                      color: outlet.isActive
                                          ? const Color(0xFF34D399)
                                          : const Color(0xFF94A3B8),
                                      size: 20,
                                    ),
                                  ),
                                  const SizedBox(width: 12),
                                  Expanded(
                                    child: Column(
                                      crossAxisAlignment:
                                          CrossAxisAlignment.start,
                                      children: [
                                        Row(
                                          children: [
                                            Flexible(
                                              child: Text(
                                                outlet.name,
                                                style: const TextStyle(
                                                  color: Colors.white,
                                                  fontSize: 16,
                                                  fontWeight: FontWeight.bold,
                                                ),
                                              ),
                                            ),
                                            const SizedBox(width: 8),
                                            Container(
                                              padding:
                                                  const EdgeInsets.symmetric(
                                                    horizontal: 6,
                                                    vertical: 2,
                                                  ),
                                              decoration: BoxDecoration(
                                                color: const Color(0xFF334155),
                                                borderRadius:
                                                    BorderRadius.circular(4),
                                              ),
                                              child: Text(
                                                outlet.code,
                                                style: const TextStyle(
                                                  color: Color(0xFF38BDF8),
                                                  fontSize: 11,
                                                  fontWeight: FontWeight.bold,
                                                ),
                                              ),
                                            ),
                                          ],
                                        ),
                                        const SizedBox(height: 2),
                                        Text(
                                          outlet.timezone != null
                                              ? 'Zona: ${outlet.timezone}'
                                              : 'Zona: Mengikuti Bisnis',
                                          style: const TextStyle(
                                            color: Color(0xFF94A3B8),
                                            fontSize: 12,
                                          ),
                                        ),
                                      ],
                                    ),
                                  ),
                                  // Status badge
                                  Container(
                                    padding: const EdgeInsets.symmetric(
                                      horizontal: 8,
                                      vertical: 4,
                                    ),
                                    decoration: BoxDecoration(
                                      color: outlet.isActive
                                          ? const Color(
                                              0xFF065F46,
                                            ).withAlpha(100)
                                          : const Color(
                                              0xFF7F1D1D,
                                            ).withAlpha(100),
                                      borderRadius: BorderRadius.circular(6),
                                      border: Border.all(
                                        color: outlet.isActive
                                            ? const Color(0xFF059669)
                                            : const Color(0xFFDC2626),
                                      ),
                                    ),
                                    child: Text(
                                      outlet.isActive ? 'AKTIF' : 'NONAKTIF',
                                      style: TextStyle(
                                        color: outlet.isActive
                                            ? const Color(0xFF34D399)
                                            : const Color(0xFFF87171),
                                        fontSize: 11,
                                        fontWeight: FontWeight.bold,
                                      ),
                                    ),
                                  ),
                                ],
                              ),

                              if (outlet.addressLine != null ||
                                  outlet.city != null) ...[
                                const SizedBox(height: 12),
                                Row(
                                  children: [
                                    const Icon(
                                      Icons.place,
                                      color: Color(0xFF64748B),
                                      size: 16,
                                    ),
                                    const SizedBox(width: 6),
                                    Expanded(
                                      child: Text(
                                        [
                                              outlet.addressLine,
                                              outlet.city,
                                              outlet.province,
                                            ]
                                            .where(
                                              (e) => e != null && e.isNotEmpty,
                                            )
                                            .join(', '),
                                        style: const TextStyle(
                                          color: Color(0xFFCBD5E1),
                                          fontSize: 13,
                                        ),
                                        maxLines: 1,
                                        overflow: TextOverflow.ellipsis,
                                      ),
                                    ),
                                  ],
                                ),
                              ],

                              if (outlet.phone != null) ...[
                                const SizedBox(height: 4),
                                Row(
                                  children: [
                                    const Icon(
                                      Icons.phone,
                                      color: Color(0xFF64748B),
                                      size: 16,
                                    ),
                                    const SizedBox(width: 6),
                                    Text(
                                      outlet.phone!,
                                      style: const TextStyle(
                                        color: Color(0xFF94A3B8),
                                        fontSize: 12,
                                      ),
                                    ),
                                  ],
                                ),
                              ],

                              const SizedBox(height: 16),
                              const Divider(
                                color: Color(0xFF334155),
                                height: 1,
                              ),
                              const SizedBox(height: 10),

                              // Action Row
                              Row(
                                mainAxisAlignment:
                                    MainAxisAlignment.spaceBetween,
                                children: [
                                  if (isCurrentActive)
                                    const Row(
                                      children: [
                                        Icon(
                                          Icons.check_circle,
                                          color: Color(0xFF38BDF8),
                                          size: 18,
                                        ),
                                        SizedBox(width: 6),
                                        Text(
                                          'Outlet Aktif Saat Ini',
                                          style: TextStyle(
                                            color: Color(0xFF38BDF8),
                                            fontSize: 12,
                                            fontWeight: FontWeight.bold,
                                          ),
                                        ),
                                      ],
                                    )
                                  else
                                    TextButton.icon(
                                      key: Key(
                                        'select_active_outlet_${outlet.id}',
                                      ),
                                      onPressed: outlet.isActive
                                          ? () => _selectActiveOutlet(outlet)
                                          : null,
                                      icon: const Icon(
                                        Icons.radio_button_unchecked,
                                        size: 16,
                                      ),
                                      label: const Text('Pilih Sebagai Aktif'),
                                      style: TextButton.styleFrom(
                                        foregroundColor: const Color(
                                          0xFF38BDF8,
                                        ),
                                      ),
                                    ),
                                  Row(
                                    children: [
                                      IconButton(
                                        key: Key('toggle_status_${outlet.id}'),
                                        tooltip: outlet.isActive
                                            ? 'Nonaktifkan'
                                            : 'Aktifkan',
                                        icon: Icon(
                                          outlet.isActive
                                              ? Icons.power_settings_new
                                              : Icons.play_arrow,
                                          color: outlet.isActive
                                              ? const Color(0xFFF87171)
                                              : const Color(0xFF34D399),
                                          size: 20,
                                        ),
                                        onPressed: () => _toggleStatus(outlet),
                                      ),
                                      IconButton(
                                        key: Key('edit_outlet_${outlet.id}'),
                                        tooltip: 'Edit Outlet',
                                        icon: const Icon(
                                          Icons.edit,
                                          color: Color(0xFF94A3B8),
                                          size: 20,
                                        ),
                                        onPressed: () =>
                                            _openOutletForm(outlet),
                                      ),
                                    ],
                                  ),
                                ],
                              ),
                            ],
                          ),
                        ),
                      );
                    }),
                ],
              ),
            );
          },
        ),
      ),
    );
  }
}

class _OutletFormSheet extends StatefulWidget {
  final TenantService tenantService;
  final OutletModel? outlet;

  const _OutletFormSheet({required this.tenantService, this.outlet});

  @override
  State<_OutletFormSheet> createState() => _OutletFormSheetState();
}

class _OutletFormSheetState extends State<_OutletFormSheet> {
  final _formKey = GlobalKey<FormState>();

  late TextEditingController _nameController;
  late TextEditingController _codeController;
  late TextEditingController _phoneController;
  late TextEditingController _addressLineController;
  late TextEditingController _cityController;
  late TextEditingController _provinceController;
  late TextEditingController _postalCodeController;

  String? _timezone;
  bool _isActive = true;
  bool _isSubmitting = false;

  // Operating hours state: map of day -> {closed: bool, open: String, close: String}
  final Map<String, Map<String, dynamic>> _schedule = {
    'monday': {'closed': false, 'open': '08:00', 'close': '22:00'},
    'tuesday': {'closed': false, 'open': '08:00', 'close': '22:00'},
    'wednesday': {'closed': false, 'open': '08:00', 'close': '22:00'},
    'thursday': {'closed': false, 'open': '08:00', 'close': '22:00'},
    'friday': {'closed': false, 'open': '08:00', 'close': '22:00'},
    'saturday': {'closed': false, 'open': '08:00', 'close': '22:00'},
    'sunday': {'closed': false, 'open': '08:00', 'close': '22:00'},
  };

  @override
  void initState() {
    super.initState();
    final o = widget.outlet;
    _nameController = TextEditingController(text: o?.name ?? '');
    _codeController = TextEditingController(text: o?.code ?? '');
    _phoneController = TextEditingController(text: o?.phone ?? '');
    _addressLineController = TextEditingController(text: o?.addressLine ?? '');
    _cityController = TextEditingController(text: o?.city ?? '');
    _provinceController = TextEditingController(text: o?.province ?? '');
    _postalCodeController = TextEditingController(text: o?.postalCode ?? '');
    _timezone = o?.timezone;
    _isActive = o?.isActive ?? true;

    if (o?.operatingHours != null && o!.operatingHours!.isNotEmpty) {
      for (final entry in o.operatingHours!.entries) {
        if (_schedule.containsKey(entry.key)) {
          final val = entry.value;
          if (val is Map) {
            _schedule[entry.key] = {
              'closed': val['closed'] == true,
              'open': val['open']?.toString() ?? '08:00',
              'close': val['close']?.toString() ?? '22:00',
            };
          }
        }
      }
    }
  }

  @override
  void dispose() {
    _nameController.dispose();
    _codeController.dispose();
    _phoneController.dispose();
    _addressLineController.dispose();
    _cityController.dispose();
    _provinceController.dispose();
    _postalCodeController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSubmitting = true);

    try {
      final payload = <String, dynamic>{
        'name': _nameController.text.trim(),
        'code': _codeController.text.trim().toUpperCase(),
        'phone': _phoneController.text.trim().isNotEmpty
            ? _phoneController.text.trim()
            : null,
        'address_line': _addressLineController.text.trim().isNotEmpty
            ? _addressLineController.text.trim()
            : null,
        'city': _cityController.text.trim().isNotEmpty
            ? _cityController.text.trim()
            : null,
        'province': _provinceController.text.trim().isNotEmpty
            ? _provinceController.text.trim()
            : null,
        'postal_code': _postalCodeController.text.trim().isNotEmpty
            ? _postalCodeController.text.trim()
            : null,
        'timezone': _timezone,
        'operating_hours': _schedule,
      };

      bool ok;
      if (widget.outlet == null) {
        final tenantId = widget.tenantService.activeTenant?.id;
        if (tenantId == null) {
          setState(() => _isSubmitting = false);
          return;
        }
        ok = await widget.tenantService.createOutlet(
          tenantId: tenantId,
          name: payload['name'] as String,
          code: payload['code'] as String,
          phone: payload['phone'] as String?,
          addressLine: payload['address_line'] as String?,
          city: payload['city'] as String?,
          province: payload['province'] as String?,
          postalCode: payload['postal_code'] as String?,
          timezone: payload['timezone'] as String?,
          operatingHours: payload['operating_hours'] as Map<String, dynamic>?,
        );
      } else {
        payload['status'] = _isActive ? 'active' : 'inactive';
        ok = await widget.tenantService.updateOutlet(
          outletId: widget.outlet!.id,
          data: payload,
        );
      }

      if (ok && mounted) {
        Navigator.of(context).pop();
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              widget.outlet == null
                  ? 'Outlet baru berhasil ditambahkan.'
                  : 'Outlet berhasil diperbarui.',
            ),
            backgroundColor: const Color(0xFF059669),
          ),
        );
      }
    } finally {
      if (mounted) {
        setState(() => _isSubmitting = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final isEdit = widget.outlet != null;

    return Padding(
      padding: EdgeInsets.only(
        bottom: MediaQuery.of(context).viewInsets.bottom,
      ),
      child: ConstrainedBox(
        constraints: BoxConstraints(
          maxHeight: MediaQuery.of(context).size.height * 0.85,
        ),
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              mainAxisSize: MainAxisSize.min,
              children: [
                Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      isEdit ? 'Edit Outlet' : 'Tambah Outlet Baru',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 18,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close, color: Color(0xFF94A3B8)),
                      onPressed: () => Navigator.of(context).pop(),
                    ),
                  ],
                ),
                const SizedBox(height: 16),

                // Name & Code
                TextFormField(
                  key: const Key('outlet_form_name_input'),
                  controller: _nameController,
                  style: const TextStyle(color: Colors.white),
                  decoration: _inputDecoration('Nama Outlet *', Icons.store),
                  validator: (val) => val == null || val.trim().isEmpty
                      ? 'Nama outlet wajib diisi.'
                      : null,
                ),
                const SizedBox(height: 12),
                TextFormField(
                  key: const Key('outlet_form_code_input'),
                  controller: _codeController,
                  style: const TextStyle(color: Colors.white),
                  decoration: _inputDecoration(
                    'Kode Outlet (Contoh: PUSAT, CABANG01) *',
                    Icons.tag,
                  ),
                  validator: (val) => val == null || val.trim().isEmpty
                      ? 'Kode outlet wajib diisi.'
                      : null,
                ),
                const SizedBox(height: 12),

                // Phone
                TextFormField(
                  key: const Key('outlet_form_phone_input'),
                  controller: _phoneController,
                  keyboardType: TextInputType.phone,
                  style: const TextStyle(color: Colors.white),
                  decoration: _inputDecoration(
                    'Nomor Telepon Outlet',
                    Icons.phone,
                  ),
                ),
                const SizedBox(height: 12),

                // Address
                TextFormField(
                  key: const Key('outlet_form_address_input'),
                  controller: _addressLineController,
                  style: const TextStyle(color: Colors.white),
                  decoration: _inputDecoration('Alamat Lengkap', Icons.place),
                ),
                const SizedBox(height: 12),
                Row(
                  children: [
                    Expanded(
                      child: TextFormField(
                        key: const Key('outlet_form_city_input'),
                        controller: _cityController,
                        style: const TextStyle(color: Colors.white),
                        decoration: _inputDecoration(
                          'Kota',
                          Icons.location_city,
                        ),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: TextFormField(
                        key: const Key('outlet_form_province_input'),
                        controller: _provinceController,
                        style: const TextStyle(color: Colors.white),
                        decoration: _inputDecoration('Provinsi', Icons.map),
                      ),
                    ),
                  ],
                ),
                const SizedBox(height: 12),

                // Timezone
                DropdownButtonFormField<String?>(
                  key: const Key('outlet_form_timezone_dropdown'),
                  initialValue: _timezone,
                  isExpanded: true,
                  dropdownColor: const Color(0xFF1E293B),
                  style: const TextStyle(color: Colors.white),
                  decoration: _inputDecoration(
                    'Zona Waktu (Opsional)',
                    Icons.access_time,
                  ),
                  items: const [
                    DropdownMenuItem(
                      value: null,
                      child: Text('Mengikuti Bisnis (Default)'),
                    ),
                    DropdownMenuItem(
                      value: 'Asia/Jakarta',
                      child: Text('WIB (Asia/Jakarta)'),
                    ),
                    DropdownMenuItem(
                      value: 'Asia/Makassar',
                      child: Text('WITA (Asia/Makassar)'),
                    ),
                    DropdownMenuItem(
                      value: 'Asia/Jayapura',
                      child: Text('WIT (Asia/Jayapura)'),
                    ),
                  ],
                  onChanged: (val) => setState(() => _timezone = val),
                ),
                const SizedBox(height: 20),

                // Operating Hours Section
                const Text(
                  'Jam Operasional Mingguan',
                  style: TextStyle(
                    color: Color(0xFF38BDF8),
                    fontSize: 14,
                    fontWeight: FontWeight.bold,
                  ),
                ),
                const SizedBox(height: 8),
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: const Color(0xFF0F172A),
                    borderRadius: BorderRadius.circular(10),
                    border: Border.all(color: const Color(0xFF334155)),
                  ),
                  child: Column(
                    children: _schedule.entries.map((entry) {
                      final dayKey = entry.key;
                      final dayData = entry.value;
                      final isClosed = dayData['closed'] == true;

                      final dayLabels = {
                        'monday': 'Senin',
                        'tuesday': 'Selasa',
                        'wednesday': 'Rabu',
                        'thursday': 'Kamis',
                        'friday': 'Jumat',
                        'saturday': 'Sabtu',
                        'sunday': 'Minggu',
                      };

                      return Padding(
                        padding: const EdgeInsets.symmetric(vertical: 4.0),
                        child: Row(
                          children: [
                            SizedBox(
                              width: 70,
                              child: Text(
                                dayLabels[dayKey] ?? dayKey,
                                style: const TextStyle(
                                  color: Colors.white,
                                  fontSize: 13,
                                  fontWeight: FontWeight.w500,
                                ),
                              ),
                            ),
                            Checkbox(
                              value: isClosed,
                              activeColor: const Color(0xFFEF4444),
                              onChanged: (val) {
                                setState(() {
                                  dayData['closed'] = val ?? false;
                                });
                              },
                            ),
                            const Text(
                              'Tutup',
                              style: TextStyle(
                                color: Color(0xFF94A3B8),
                                fontSize: 12,
                              ),
                            ),
                            const Spacer(),
                            if (!isClosed) ...[
                              Text(
                                '${dayData['open']} - ${dayData['close']}',
                                style: const TextStyle(
                                  color: Color(0xFF38BDF8),
                                  fontSize: 12,
                                ),
                              ),
                            ] else
                              const Text(
                                'Libur',
                                style: TextStyle(
                                  color: Color(0xFFEF4444),
                                  fontSize: 12,
                                ),
                              ),
                          ],
                        ),
                      );
                    }).toList(),
                  ),
                ),
                const SizedBox(height: 24),

                // Submit Button
                ElevatedButton(
                  key: const Key('outlet_form_submit_button'),
                  onPressed: _isSubmitting ? null : _submit,
                  style: ElevatedButton.styleFrom(
                    backgroundColor: const Color(0xFF0284C7),
                    foregroundColor: Colors.white,
                    padding: const EdgeInsets.symmetric(vertical: 16),
                    shape: RoundedRectangleBorder(
                      borderRadius: BorderRadius.circular(10),
                    ),
                  ),
                  child: _isSubmitting
                      ? const SizedBox(
                          height: 20,
                          width: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2,
                            valueColor: AlwaysStoppedAnimation<Color>(
                              Colors.white,
                            ),
                          ),
                        )
                      : Text(
                          isEdit ? 'Perbarui Outlet' : 'Simpan Outlet',
                          style: const TextStyle(
                            fontSize: 16,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  InputDecoration _inputDecoration(String label, IconData icon) {
    return InputDecoration(
      labelText: label,
      labelStyle: const TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
      prefixIcon: Icon(icon, color: const Color(0xFF94A3B8), size: 20),
      filled: true,
      fillColor: const Color(0xFF0F172A),
      border: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Color(0xFF334155)),
      ),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(10),
        borderSide: const BorderSide(color: Color(0xFF334155)),
      ),
    );
  }
}
