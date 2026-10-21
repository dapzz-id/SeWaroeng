import 'package:flutter/material.dart';
import '../../core/table/table_models.dart';
import '../../core/table/table_service.dart';
import 'floor_plan_canvas.dart';

class FloorPlanEditorScreen extends StatefulWidget {
  final TableService tableService;
  final String outletId;
  final String outletName;

  const FloorPlanEditorScreen({
    super.key,
    required this.tableService,
    required this.outletId,
    required this.outletName,
  });

  @override
  State<FloorPlanEditorScreen> createState() => _FloorPlanEditorScreenState();
}

class _FloorPlanEditorScreenState extends State<FloorPlanEditorScreen> {
  FloorPlanData? _currentPlan;
  List<TableModel> _currentTables = [];
  bool _isSaving = false;
  TableModel? _selectedTableForEdit;
  double _dragSensitivity = 1.8; // High default responsiveness

  @override
  void initState() {
    super.initState();
    _loadData();
  }

  Future<void> _loadData() async {
    await widget.tableService.fetchFloorPlan(widget.outletId);
    if (mounted) {
      setState(() {
        _currentPlan = widget.tableService.floorPlan ??
            const FloorPlanData(
              canvasWidth: 960,
              canvasHeight: 640,
              elements: [],
            );
        _currentTables = List.from(widget.tableService.tables);
      });
    }
  }

  Future<void> _saveFloorPlan() async {
    if (_currentPlan == null) return;
    setState(() => _isSaving = true);

    final success = await widget.tableService.saveFloorPlan(
      outletId: widget.outletId,
      floorPlan: _currentPlan!,
      tables: _currentTables,
    );

    if (mounted) {
      setState(() => _isSaving = false);
      if (success) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('✅ Tata letak denah meja berhasil disimpan!'),
            backgroundColor: Colors.green,
          ),
        );
      } else {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(widget.tableService.errorMessage ?? 'Gagal menyimpan denah.'),
            backgroundColor: Colors.red,
          ),
        );
      }
    }
  }

  void _showEditTableModal(TableModel table) {
    final nameCtrl = TextEditingController(text: table.name);
    final capCtrl = TextEditingController(text: '${table.capacity}');
    String selectedShape = table.shape;
    String selectedZone = table.zone;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) => AlertDialog(
          title: Text('Edit ${table.code} (${table.name})'),
          content: SizedBox(
            width: 360,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: nameCtrl,
                  decoration: const InputDecoration(labelText: 'Label / Nama Meja'),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: capCtrl,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(labelText: 'Kapasitas (Orang)'),
                ),
                const SizedBox(height: 16),
                DropdownButtonFormField<String>(
                  initialValue: selectedShape,
                  decoration: const InputDecoration(labelText: 'Bentuk Meja'),
                  items: const [
                    DropdownMenuItem(value: 'rect', child: Text('Persegi Panjang')),
                    DropdownMenuItem(value: 'square', child: Text('Bujur Sangkar')),
                    DropdownMenuItem(value: 'circle', child: Text('Bulat / Bundar')),
                  ],
                  onChanged: (val) {
                    if (val != null) setModalState(() => selectedShape = val);
                  },
                ),
                const SizedBox(height: 16),
                DropdownButtonFormField<String>(
                  initialValue: selectedZone,
                  decoration: const InputDecoration(labelText: 'Area / Zona Meja'),
                  items: const [
                    DropdownMenuItem(value: 'indoor', child: Text('Indoor (AC)')),
                    DropdownMenuItem(value: 'outdoor', child: Text('Outdoor / Taman')),
                    DropdownMenuItem(value: 'vip', child: Text('VIP Lounge')),
                  ],
                  onChanged: (val) {
                    if (val != null) setModalState(() => selectedZone = val);
                  },
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Batal'),
            ),
            FilledButton(
              onPressed: () {
                final newCap = int.tryParse(capCtrl.text.trim()) ?? table.capacity;
                final idx = _currentTables.indexWhere((t) => t.id == table.id);
                if (idx != -1) {
                  setState(() {
                    _currentTables[idx] = table.copyWith(
                      name: nameCtrl.text.trim(),
                      capacity: newCap,
                      shape: selectedShape,
                      zone: selectedZone,
                    );
                  });
                }
                Navigator.pop(ctx);
              },
              child: const Text('Terapkan'),
            ),
          ],
        ),
      ),
    );
  }

  /// 🧱 Modal to Edit or Delete a Wall / Divider line
  void _showEditWallModal(FloorPlanElement wall) {
    double thickness = wall.thickness ?? 6.0;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setModalState) => AlertDialog(
          title: const Row(
            children: [
              Icon(Icons.border_vertical, color: Colors.amber),
              SizedBox(width: 8),
              Text('Atur Garis Pembatas (Dinding)'),
            ],
          ),
          content: SizedBox(
            width: 360,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text(
                  '💡 Tips: Anda dapat menggeser posisi seluruh garis lewat tombol tengah, atau menarik bulatan ujung untuk mengubah panjang & sudutnya.',
                  style: TextStyle(color: Colors.white70, fontSize: 12),
                ),
                const SizedBox(height: 16),
                Text('Ketebalan Garis: ${thickness.toInt()} px'),
                Slider(
                  value: thickness,
                  min: 3.0,
                  max: 18.0,
                  divisions: 5,
                  label: '${thickness.toInt()} px',
                  onChanged: (v) {
                    setModalState(() => thickness = v);
                  },
                ),
                const SizedBox(height: 12),
                const Text('Luruskan Garis Otomatis:'),
                const SizedBox(height: 8),
                Row(
                  children: [
                    Expanded(
                      child: OutlinedButton(
                        onPressed: () {
                          // Align Horizontal: y2 = y1
                          final idx = _currentPlan!.elements.indexWhere((e) => e.id == wall.id);
                          if (idx != -1) {
                            setState(() {
                              _currentPlan!.elements[idx] = wall.copyWith(
                                y2: wall.y1,
                                thickness: thickness,
                              );
                            });
                          }
                          Navigator.pop(ctx);
                        },
                        child: const Text('Horizontal (Rata)'),
                      ),
                    ),
                    const SizedBox(width: 8),
                    Expanded(
                      child: OutlinedButton(
                        onPressed: () {
                          // Align Vertical: x2 = x1
                          final idx = _currentPlan!.elements.indexWhere((e) => e.id == wall.id);
                          if (idx != -1) {
                            setState(() {
                              _currentPlan!.elements[idx] = wall.copyWith(
                                x2: wall.x1,
                                thickness: thickness,
                              );
                            });
                          }
                          Navigator.pop(ctx);
                        },
                        child: const Text('Vertikal (Tegak)'),
                      ),
                    ),
                  ],
                ),
                const Divider(height: 28),
                SizedBox(
                  width: double.infinity,
                  child: TextButton.icon(
                    style: TextButton.styleFrom(foregroundColor: Colors.red),
                    onPressed: () {
                      setState(() {
                        _currentPlan = _currentPlan!.copyWith(
                          elements: _currentPlan!.elements.where((e) => e.id != wall.id).toList(),
                        );
                      });
                      Navigator.pop(ctx);
                      ScaffoldMessenger.of(context).showSnackBar(
                        const SnackBar(content: Text('Garis pembatas dihapus')),
                      );
                    },
                    icon: const Icon(Icons.delete_outline),
                    label: const Text('Hapus Garis Pembatas Ini'),
                  ),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
            FilledButton(
              onPressed: () {
                final idx = _currentPlan!.elements.indexWhere((e) => e.id == wall.id);
                if (idx != -1) {
                  setState(() {
                    _currentPlan!.elements[idx] = wall.copyWith(thickness: thickness);
                  });
                }
                Navigator.pop(ctx);
              },
              child: const Text('Terapkan'),
            ),
          ],
        ),
      ),
    );
  }

  /// 🏷️ Modal to Edit or Delete a Text Landmark
  void _showEditTextModal(FloorPlanElement txt) {
    final textCtrl = TextEditingController(text: txt.label);

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Ubah Teks Penanda'),
        content: SizedBox(
          width: 320,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              TextField(
                controller: textCtrl,
                decoration: const InputDecoration(labelText: 'Teks Penanda'),
              ),
              const SizedBox(height: 16),
              TextButton.icon(
                style: TextButton.styleFrom(foregroundColor: Colors.red),
                onPressed: () {
                  setState(() {
                    _currentPlan = _currentPlan!.copyWith(
                      elements: _currentPlan!.elements.where((e) => e.id != txt.id).toList(),
                    );
                  });
                  Navigator.pop(ctx);
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Teks penanda dihapus')),
                  );
                },
                icon: const Icon(Icons.delete_outline),
                label: const Text('Hapus Penanda Ini'),
              ),
            ],
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
          FilledButton(
            onPressed: () {
              if (textCtrl.text.trim().isNotEmpty) {
                final idx = _currentPlan!.elements.indexWhere((e) => e.id == txt.id);
                if (idx != -1) {
                  setState(() {
                    _currentPlan!.elements[idx] = txt.copyWith(label: textCtrl.text.trim());
                  });
                }
              }
              Navigator.pop(ctx);
            },
            child: const Text('Terapkan'),
          ),
        ],
      ),
    );
  }

  void _showAddTextDialog() {
    final textCtrl = TextEditingController(text: 'PINTU MASUK');

    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Tambah Text / Penanda Denah'),
        content: SizedBox(
          width: 320,
          child: TextField(
            controller: textCtrl,
            decoration: const InputDecoration(
              hintText: 'Contoh: 🚪 PINTU KELUAR, ☕ AREA BAR',
              labelText: 'Teks Penanda',
            ),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
          FilledButton(
            onPressed: () {
              if (textCtrl.text.trim().isNotEmpty && _currentPlan != null) {
                final newElem = FloorPlanElement(
                  id: 'txt-${DateTime.now().millisecondsSinceEpoch}',
                  type: 'text',
                  label: textCtrl.text.trim(),
                  x: 100,
                  y: 100,
                  fontSize: 13,
                );
                setState(() {
                  _currentPlan = _currentPlan!.copyWith(
                    elements: [..._currentPlan!.elements, newElem],
                  );
                });
              }
              Navigator.pop(ctx);
            },
            child: const Text('Tambah'),
          ),
        ],
      ),
    );
  }

  void _showAddWallDialog() {
    showDialog(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Tambah Garis Pembatas (Dinding)'),
        content: const Text(
          'Pilih jenis garis pembatas yang ingin ditambahkan ke denah:',
        ),
        actions: [
          TextButton(
            onPressed: () {
              // Add vertical divider
              final newElem = FloorPlanElement(
                id: 'wall-${DateTime.now().millisecondsSinceEpoch}',
                type: 'wall',
                x1: 450,
                y1: 50,
                x2: 450,
                y2: 550,
                thickness: 6,
                color: '#64748b',
              );
              setState(() {
                _currentPlan = _currentPlan!.copyWith(
                  elements: [..._currentPlan!.elements, newElem],
                );
              });
              Navigator.pop(ctx);
            },
            child: const Text('Garis Vertikal (Tegak)'),
          ),
          FilledButton(
            onPressed: () {
              // Add horizontal divider
              final newElem = FloorPlanElement(
                id: 'wall-${DateTime.now().millisecondsSinceEpoch}',
                type: 'wall',
                x1: 50,
                y1: 300,
                x2: 850,
                y2: 300,
                thickness: 6,
                color: '#64748b',
              );
              setState(() {
                _currentPlan = _currentPlan!.copyWith(
                  elements: [..._currentPlan!.elements, newElem],
                );
              });
              Navigator.pop(ctx);
            },
            child: const Text('Garis Horizontal (Mendatar)'),
          ),
        ],
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    if (_currentPlan == null) {
      return Scaffold(
        appBar: AppBar(title: Text('Atur Denah - ${widget.outletName}')),
        body: const Center(child: CircularProgressIndicator()),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: Text('Editor Tata Letak Denah - ${widget.outletName}'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _loadData,
            tooltip: 'Reset Perubahan',
          ),
          const SizedBox(width: 8),
          FilledButton.icon(
            onPressed: _isSaving ? null : _saveFloorPlan,
            icon: _isSaving
                ? const SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : const Icon(Icons.save),
            label: const Text('Simpan Denah'),
          ),
          const SizedBox(width: 16),
        ],
      ),
      body: Column(
        children: [
          // Toolbar: Controls & Tools & Sensitivity Switcher
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
            color: const Color(0xFF1E293B),
            child: Row(
              children: [
                const Icon(Icons.touch_app_outlined, size: 20, color: Colors.amber),
                const SizedBox(width: 8),
                const Expanded(
                  child: Text(
                    'Geser meja / garis pembatas untuk tata letak. Tarik bulatan ujung garis untuk ubah panjang & sudut.',
                    style: TextStyle(color: Colors.white70, fontSize: 12),
                  ),
                ),

                // Sensitivity Controller Dropdown
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 2),
                  decoration: BoxDecoration(
                    color: const Color(0xFF0F172A),
                    borderRadius: BorderRadius.circular(8),
                    border: Border.all(color: const Color(0xFF475569)),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const Icon(Icons.speed, size: 16, color: Colors.cyanAccent),
                      const SizedBox(width: 6),
                      DropdownButton<double>(
                        value: _dragSensitivity,
                        underline: const SizedBox.shrink(),
                        dropdownColor: const Color(0xFF1E293B),
                        style: const TextStyle(color: Colors.white, fontSize: 12),
                        items: const [
                          DropdownMenuItem(value: 1.0, child: Text('Sensitivitas: 1.0x (Halus)')),
                          DropdownMenuItem(value: 1.5, child: Text('Sensitivitas: 1.5x (Normal)')),
                          DropdownMenuItem(value: 1.8, child: Text('Sensitivitas: 1.8x (Cepat)')),
                          DropdownMenuItem(value: 2.5, child: Text('Sensitivitas: 2.5x (Sangat Cepat)')),
                        ],
                        onChanged: (val) {
                          if (val != null) {
                            setState(() => _dragSensitivity = val);
                          }
                        },
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 12),

                OutlinedButton.icon(
                  onPressed: _showAddTextDialog,
                  icon: const Icon(Icons.title, size: 16),
                  label: const Text('Tambah Teks'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Color(0xFF475569)),
                  ),
                ),
                const SizedBox(width: 8),
                OutlinedButton.icon(
                  onPressed: _showAddWallDialog,
                  icon: const Icon(Icons.border_vertical, size: 16),
                  label: const Text('Tambah Pembatas'),
                  style: OutlinedButton.styleFrom(
                    foregroundColor: Colors.white,
                    side: const BorderSide(color: Color(0xFF475569)),
                  ),
                ),
              ],
            ),
          ),

          // Main Canvas
          Expanded(
            child: FloorPlanCanvas(
              floorPlan: _currentPlan!,
              tables: _currentTables,
              isEditor: true,
              dragSensitivity: _dragSensitivity,
              selectedTable: _selectedTableForEdit,
              onTableSelected: (table) {
                setState(() => _selectedTableForEdit = table);
                _showEditTableModal(table);
              },
              onElementSelected: (elem) {
                if (elem.type == 'wall') {
                  _showEditWallModal(elem);
                } else if (elem.type == 'text') {
                  _showEditTextModal(elem);
                }
              },
              onTablesUpdated: (updated) {
                setState(() => _currentTables = updated);
              },
              onPlanUpdated: (updated) {
                setState(() => _currentPlan = updated);
              },
            ),
          ),
        ],
      ),
    );
  }
}
