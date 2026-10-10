import 'package:flutter/material.dart';
import '../../core/catalog/catalog_service.dart';

class ModifierManagementSheet extends StatefulWidget {
  final CatalogService catalogService;

  const ModifierManagementSheet({super.key, required this.catalogService});

  @override
  State<ModifierManagementSheet> createState() =>
      _ModifierManagementSheetState();
}

class _ModifierManagementSheetState extends State<ModifierManagementSheet> {
  final _nameController = TextEditingController();
  String _selectionMode = 'single';
  int _minSelection = 0;
  int _maxSelection = 1;
  bool _isRequired = false;
  bool _isCreating = false;

  final List<Map<String, dynamic>> _newModifiers = [
    {'name': '', 'price_delta': 0},
  ];

  @override
  void initState() {
    super.initState();
    widget.catalogService.fetchModifierGroups();
  }

  @override
  void dispose() {
    _nameController.dispose();
    super.dispose();
  }

  void _addModifierRow() {
    setState(() {
      _newModifiers.add({'name': '', 'price_delta': 0});
    });
  }

  void _removeModifierRow(int index) {
    if (_newModifiers.length > 1) {
      setState(() {
        _newModifiers.removeAt(index);
      });
    }
  }

  Future<void> _submitModifierGroup() async {
    final name = _nameController.text.trim();
    if (name.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Nama grup modifier wajib diisi.')),
      );
      return;
    }

    final validModifiers = _newModifiers
        .where((m) => (m['name'] as String).trim().isNotEmpty)
        .map(
          (m) => {
            'name': (m['name'] as String).trim(),
            'price_delta': m['price_delta'] ?? 0,
          },
        )
        .toList();

    if (validModifiers.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Tambahkan minimal 1 opsi modifier.')),
      );
      return;
    }

    setState(() => _isCreating = true);

    final res = await widget.catalogService.createModifierGroup(
      name: name,
      selectionMode: _selectionMode,
      minSelection: _minSelection,
      maxSelection: _maxSelection,
      isRequired: _isRequired,
      modifiers: validModifiers,
    );

    setState(() => _isCreating = false);

    if (res != null && mounted) {
      _nameController.clear();
      _newModifiers.clear();
      _newModifiers.add({'name': '', 'price_delta': 0});
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Grup modifier "${res.name}" berhasil dibuat.')),
      );
    } else if (mounted && widget.catalogService.errorMessage != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(widget.catalogService.errorMessage!),
          backgroundColor: Colors.red.shade800,
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.catalogService,
      builder: (context, _) {
        final groups = widget.catalogService.modifierGroups;

        return Container(
          height: MediaQuery.of(context).size.height * 0.85,
          decoration: const BoxDecoration(
            color: Colors.white,
            borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
          ),
          padding: EdgeInsets.only(
            left: 20,
            right: 20,
            top: 20,
            bottom: MediaQuery.of(context).viewInsets.bottom + 20,
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text(
                    'Kelola Modifier / Opsi Tambahan',
                    style: TextStyle(
                      fontSize: 18,
                      fontWeight: FontWeight.bold,
                      color: Color(0xFF1E293B),
                    ),
                  ),
                  IconButton(
                    icon: const Icon(Icons.close),
                    onPressed: () => Navigator.of(context).pop(),
                  ),
                ],
              ),
              const Divider(),

              Expanded(
                child: ListView(
                  children: [
                    // Form Section
                    Container(
                      padding: const EdgeInsets.all(14),
                      decoration: BoxDecoration(
                        color: const Color(0xFFF8FAFC),
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: const Color(0xFFE2E8F0)),
                      ),
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                          const Text(
                            'Buat Grup Modifier Baru',
                            style: TextStyle(
                              fontWeight: FontWeight.bold,
                              fontSize: 15,
                              color: Color(0xFF0F766E),
                            ),
                          ),
                          const SizedBox(height: 12),
                          TextField(
                            controller: _nameController,
                            decoration: const InputDecoration(
                              labelText: 'Nama Grup Modifier *',
                              hintText:
                                  'Misal: Pilihan Susu, Level Gula, Topping',
                              isDense: true,
                              border: OutlineInputBorder(),
                            ),
                          ),
                          const SizedBox(height: 12),
                          Row(
                            children: [
                              Expanded(
                                child: DropdownButtonFormField<String>(
                                  initialValue: _selectionMode,
                                  decoration: const InputDecoration(
                                    labelText: 'Mode Seleksi',
                                    isDense: true,
                                    border: OutlineInputBorder(),
                                  ),
                                  items: const [
                                    DropdownMenuItem(
                                      value: 'single',
                                      child: Text('Pilih Satu (Single)'),
                                    ),
                                    DropdownMenuItem(
                                      value: 'multiple',
                                      child: Text('Bisa Pilih Banyak'),
                                    ),
                                  ],
                                  onChanged: (val) {
                                    if (val != null) {
                                      setState(() {
                                        _selectionMode = val;
                                        if (val == 'single') {
                                          _maxSelection = 1;
                                        }
                                      });
                                    }
                                  },
                                ),
                              ),
                              const SizedBox(width: 8),
                              Expanded(
                                child: CheckboxListTile(
                                  title: const Text(
                                    'Wajib Pilih',
                                    style: TextStyle(fontSize: 12),
                                  ),
                                  value: _isRequired,
                                  dense: true,
                                  contentPadding: EdgeInsets.zero,
                                  controlAffinity:
                                      ListTileControlAffinity.leading,
                                  onChanged: (val) {
                                    setState(() {
                                      _isRequired = val ?? false;
                                      if (_isRequired && _minSelection == 0) {
                                        _minSelection = 1;
                                      }
                                    });
                                  },
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 12),
                          const Text(
                            'Daftar Opsi / Pilihan:',
                            style: TextStyle(
                              fontWeight: FontWeight.w600,
                              fontSize: 13,
                            ),
                          ),
                          const SizedBox(height: 8),

                          ..._newModifiers.asMap().entries.map((entry) {
                            final idx = entry.key;
                            final item = entry.value;

                            return Padding(
                              padding: const EdgeInsets.only(bottom: 8),
                              child: Row(
                                children: [
                                  Expanded(
                                    flex: 3,
                                    child: TextFormField(
                                      initialValue: item['name'] as String,
                                      decoration: InputDecoration(
                                        hintText: 'Opsi #${idx + 1}',
                                        isDense: true,
                                        border: const OutlineInputBorder(),
                                      ),
                                      onChanged: (v) => item['name'] = v,
                                    ),
                                  ),
                                  const SizedBox(width: 8),
                                  Expanded(
                                    flex: 2,
                                    child: TextFormField(
                                      initialValue: (item['price_delta'] ?? 0)
                                          .toString(),
                                      keyboardType: TextInputType.number,
                                      decoration: const InputDecoration(
                                        hintText: 'Harga (+Rp)',
                                        isDense: true,
                                        border: OutlineInputBorder(),
                                      ),
                                      onChanged: (v) => item['price_delta'] =
                                          int.tryParse(v) ?? 0,
                                    ),
                                  ),
                                  IconButton(
                                    icon: const Icon(
                                      Icons.remove_circle_outline,
                                      color: Colors.red,
                                    ),
                                    onPressed: () => _removeModifierRow(idx),
                                  ),
                                ],
                              ),
                            );
                          }),

                          Align(
                            alignment: Alignment.centerLeft,
                            child: TextButton.icon(
                              onPressed: _addModifierRow,
                              icon: const Icon(Icons.add, size: 16),
                              label: const Text('Tambah Opsi'),
                            ),
                          ),
                          const SizedBox(height: 8),
                          ElevatedButton.icon(
                            onPressed: _isCreating
                                ? null
                                : _submitModifierGroup,
                            icon: _isCreating
                                ? const SizedBox(
                                    width: 16,
                                    height: 16,
                                    child: CircularProgressIndicator(
                                      strokeWidth: 2,
                                      color: Colors.white,
                                    ),
                                  )
                                : const Icon(Icons.save, size: 18),
                            label: const Text('Simpan Grup Modifier'),
                            style: ElevatedButton.styleFrom(
                              backgroundColor: const Color(0xFF0F766E),
                              foregroundColor: Colors.white,
                              padding: const EdgeInsets.symmetric(vertical: 12),
                            ),
                          ),
                        ],
                      ),
                    ),

                    const SizedBox(height: 20),
                    const Text(
                      'Grup Modifier Terdaftar',
                      style: TextStyle(
                        fontSize: 15,
                        fontWeight: FontWeight.bold,
                        color: Color(0xFF334155),
                      ),
                    ),
                    const SizedBox(height: 8),

                    if (groups.isEmpty)
                      const Padding(
                        padding: EdgeInsets.symmetric(vertical: 24),
                        child: Center(
                          child: Text(
                            'Belum ada grup modifier.',
                            style: TextStyle(color: Colors.grey),
                          ),
                        ),
                      )
                    else
                      ...groups.map((group) {
                        return Card(
                          margin: const EdgeInsets.only(bottom: 8),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(8),
                            side: const BorderSide(color: Color(0xFFE2E8F0)),
                          ),
                          child: ExpansionTile(
                            title: Text(
                              group.name,
                              style: const TextStyle(
                                fontWeight: FontWeight.bold,
                                fontSize: 14,
                              ),
                            ),
                            subtitle: Text(
                              'Mode: ${group.selectionMode} | Wajib: ${group.isRequired ? "Ya" : "Tidak"} | Opsi: ${group.modifiers.length}',
                              style: const TextStyle(
                                fontSize: 12,
                                color: Color(0xFF64748B),
                              ),
                            ),
                            children: group.modifiers.map((m) {
                              return ListTile(
                                dense: true,
                                title: Text(m.name),
                                trailing: Text(
                                  m.formattedPriceDelta,
                                  style: const TextStyle(
                                    fontWeight: FontWeight.w600,
                                    color: Color(0xFF0F766E),
                                  ),
                                ),
                              );
                            }).toList(),
                          ),
                        );
                      }),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}
