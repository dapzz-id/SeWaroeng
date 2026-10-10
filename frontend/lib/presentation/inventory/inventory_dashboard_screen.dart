import 'package:flutter/material.dart';
import '../../core/inventory/inventory_models.dart';
import '../../core/inventory/inventory_service.dart';
import 'inventory_movement_history_screen.dart';
import 'stock_adjustment_dialog.dart';
import 'stock_opname_screen.dart';

class InventoryDashboardScreen extends StatefulWidget {
  final InventoryService inventoryService;

  const InventoryDashboardScreen({super.key, required this.inventoryService});

  @override
  State<InventoryDashboardScreen> createState() =>
      _InventoryDashboardScreenState();
}

class _InventoryDashboardScreenState extends State<InventoryDashboardScreen> {
  final _searchController = TextEditingController();
  bool _filterLowStockOnly = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      widget.inventoryService.fetchLocations().then((_) {
        widget.inventoryService.fetchItems();
      });
    });
  }

  @override
  void dispose() {
    _searchController.dispose();
    super.dispose();
  }

  void _onSearchChanged(String val) {
    widget.inventoryService.fetchItems(
      search: val.trim().isNotEmpty ? val.trim() : null,
      lowStock: _filterLowStockOnly ? true : null,
    );
  }

  void _openStockAdjustment({InventoryItemModel? item}) {
    showDialog(
      context: context,
      builder: (ctx) => StockAdjustmentDialog(
        inventoryService: widget.inventoryService,
        initialItem: item,
      ),
    );
  }

  void _openStockOpname() {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) =>
            StockOpnameScreen(inventoryService: widget.inventoryService),
      ),
    );
  }

  void _openItemMovements(InventoryItemModel item) {
    Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => InventoryMovementHistoryScreen(
          inventoryService: widget.inventoryService,
          item: item,
        ),
      ),
    );
  }

  void _openCreateItemDialog() {
    final nameController = TextEditingController();
    final skuController = TextEditingController();
    String unit = 'piece';
    String itemType = 'sellable';
    bool allowNegative = false;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(16),
          ),
          title: const Text('Tambah Barang Inventory'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: nameController,
                  decoration: const InputDecoration(
                    labelText: 'Nama Barang *',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                TextField(
                  controller: skuController,
                  decoration: const InputDecoration(
                    labelText: 'SKU / Kode',
                    border: OutlineInputBorder(),
                  ),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: itemType,
                  decoration: const InputDecoration(
                    labelText: 'Tipe Barang',
                    border: OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(
                      value: 'sellable',
                      child: Text('Produk Jual'),
                    ),
                    DropdownMenuItem(
                      value: 'raw_material',
                      child: Text('Bahan Baku'),
                    ),
                    DropdownMenuItem(
                      value: 'packaging',
                      child: Text('Kemasan'),
                    ),
                    DropdownMenuItem(
                      value: 'finished_good',
                      child: Text('Barang Jadi'),
                    ),
                    DropdownMenuItem(
                      value: 'asset',
                      child: Text('Aset Operasional'),
                    ),
                  ],
                  onChanged: (val) =>
                      setDlgState(() => itemType = val ?? 'sellable'),
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: unit,
                  decoration: const InputDecoration(
                    labelText: 'Satuan Dasar (Unit)',
                    border: OutlineInputBorder(),
                  ),
                  items: const [
                    DropdownMenuItem(
                      value: 'piece',
                      child: Text('Pcs (Piece)'),
                    ),
                    DropdownMenuItem(value: 'kg', child: Text('Kg (Kilogram)')),
                    DropdownMenuItem(value: 'gram', child: Text('Gram')),
                    DropdownMenuItem(value: 'liter', child: Text('Liter')),
                    DropdownMenuItem(
                      value: 'ml',
                      child: Text('Mililiter (ml)'),
                    ),
                    DropdownMenuItem(value: 'box', child: Text('Box / Kotak')),
                    DropdownMenuItem(value: 'pack', child: Text('Pack')),
                  ],
                  onChanged: (val) => setDlgState(() => unit = val ?? 'piece'),
                ),
                const SizedBox(height: 12),
                SwitchListTile(
                  title: const Text('Izinkan Stok Negatif'),
                  subtitle: const Text(
                    'Penjualan tetap diizinkan saat stok habis',
                  ),
                  value: allowNegative,
                  onChanged: (val) => setDlgState(() => allowNegative = val),
                  contentPadding: EdgeInsets.zero,
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.of(ctx).pop(),
              child: const Text('Batal'),
            ),
            ElevatedButton(
              onPressed: () async {
                if (nameController.text.trim().isEmpty) return;
                Navigator.of(ctx).pop();
                await widget.inventoryService.createItem(
                  name: nameController.text.trim(),
                  sku: skuController.text.trim().isNotEmpty
                      ? skuController.text.trim()
                      : null,
                  itemType: itemType,
                  baseUnit: unit,
                  allowNegativeStock: allowNegative,
                );
              },
              child: const Text('Simpan'),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Manajemen Inventory & Stok'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: () {
              widget.inventoryService.fetchItems();
            },
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openCreateItemDialog,
        icon: const Icon(Icons.add),
        label: const Text('Tambah Barang'),
        backgroundColor: const Color(0xFF6366F1),
        foregroundColor: Colors.white,
      ),
      body: ListenableBuilder(
        listenable: widget.inventoryService,
        builder: (context, _) {
          return RefreshIndicator(
            onRefresh: () async {
              await widget.inventoryService.fetchLocations();
              await widget.inventoryService.fetchItems();
            },
            child: SingleChildScrollView(
              physics: const AlwaysScrollableScrollPhysics(),
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // Location Selector Bar
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 16,
                      vertical: 8,
                    ),
                    decoration: BoxDecoration(
                      color: Colors.white,
                      borderRadius: BorderRadius.circular(12),
                      boxShadow: [
                        BoxShadow(
                          color: Colors.black.withValues(alpha: 0.04),
                          blurRadius: 8,
                          offset: const Offset(0, 2),
                        ),
                      ],
                    ),
                    child: Row(
                      children: [
                        const Icon(
                          Icons.store_mall_directory_outlined,
                          color: Color(0xFF6366F1),
                        ),
                        const SizedBox(width: 12),
                        const Text(
                          'Lokasi:',
                          style: TextStyle(fontWeight: FontWeight.bold),
                        ),
                        const SizedBox(width: 8),
                        Expanded(
                          child: DropdownButtonHideUnderline(
                            child: DropdownButton<InventoryLocationModel>(
                              value: widget.inventoryService.selectedLocation,
                              isExpanded: true,
                              hint: const Text('Pilih Lokasi Operasional'),
                              items: widget.inventoryService.locations.map((
                                loc,
                              ) {
                                return DropdownMenuItem(
                                  value: loc,
                                  child: Text(
                                    '${loc.name} (${loc.typeLabel})',
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                );
                              }).toList(),
                              onChanged: (val) {
                                widget.inventoryService.setSelectedLocation(
                                  val,
                                );
                              },
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),

                  // 4 KPI Summary Cards
                  Row(
                    children: [
                      Expanded(
                        child: _KpiCard(
                          title: 'Total Barang',
                          value: '${widget.inventoryService.totalItems}',
                          icon: Icons.inventory_2_outlined,
                          color: const Color(0xFF6366F1),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: _KpiCard(
                          title: 'Stok Rendah',
                          value: '${widget.inventoryService.lowStockCount}',
                          icon: Icons.warning_amber_rounded,
                          color: Colors.orange,
                          isActive: _filterLowStockOnly,
                          onTap: () {
                            setState(() {
                              _filterLowStockOnly = !_filterLowStockOnly;
                            });
                            widget.inventoryService.fetchItems(
                              lowStock: _filterLowStockOnly ? true : null,
                            );
                          },
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  Row(
                    children: [
                      Expanded(
                        child: _KpiCard(
                          title: 'Stok Habis',
                          value: '${widget.inventoryService.outOfStockCount}',
                          icon: Icons.remove_circle_outline,
                          color: Colors.red,
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: _KpiCard(
                          title: 'Stok Negatif',
                          value:
                              '${widget.inventoryService.negativeStockCount}',
                          icon: Icons.trending_down_rounded,
                          color: Colors.deepOrange,
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),

                  // Quick Action Buttons
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: () => _openStockAdjustment(),
                          icon: const Icon(Icons.tune_rounded, size: 18),
                          label: const Text('Penyesuaian Stok'),
                          style: OutlinedButton.styleFrom(
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(10),
                            ),
                          ),
                        ),
                      ),
                      const SizedBox(width: 12),
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: _openStockOpname,
                          icon: const Icon(Icons.fact_check_outlined, size: 18),
                          label: const Text('Stock Opname'),
                          style: OutlinedButton.styleFrom(
                            padding: const EdgeInsets.symmetric(vertical: 12),
                            shape: RoundedRectangleBorder(
                              borderRadius: BorderRadius.circular(10),
                            ),
                          ),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 20),

                  // Search Bar & Filter Chip
                  Row(
                    children: [
                      Expanded(
                        child: TextField(
                          controller: _searchController,
                          decoration: InputDecoration(
                            hintText: 'Cari barang atau SKU...',
                            prefixIcon: const Icon(Icons.search),
                            filled: true,
                            fillColor: Colors.white,
                            contentPadding: const EdgeInsets.symmetric(
                              horizontal: 16,
                              vertical: 12,
                            ),
                            border: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(10),
                              borderSide: BorderSide(
                                color: Colors.grey.shade300,
                              ),
                            ),
                            enabledBorder: OutlineInputBorder(
                              borderRadius: BorderRadius.circular(10),
                              borderSide: BorderSide(
                                color: Colors.grey.shade300,
                              ),
                            ),
                          ),
                          onChanged: _onSearchChanged,
                        ),
                      ),
                      const SizedBox(width: 10),
                      FilterChip(
                        label: const Text('Stok Rendah'),
                        selected: _filterLowStockOnly,
                        onSelected: (selected) {
                          setState(() => _filterLowStockOnly = selected);
                          widget.inventoryService.fetchItems(
                            search: _searchController.text.trim().isNotEmpty
                                ? _searchController.text.trim()
                                : null,
                            lowStock: selected ? true : null,
                          );
                        },
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),

                  // Item List
                  if (widget.inventoryService.isLoading &&
                      widget.inventoryService.items.isEmpty)
                    const Padding(
                      padding: EdgeInsets.all(40),
                      child: Center(child: CircularProgressIndicator()),
                    )
                  else if (widget.inventoryService.items.isEmpty)
                    Container(
                      padding: const EdgeInsets.all(40),
                      alignment: Alignment.center,
                      child: Column(
                        children: [
                          Icon(
                            Icons.inventory_2_outlined,
                            size: 48,
                            color: Colors.grey.shade400,
                          ),
                          const SizedBox(height: 12),
                          Text(
                            'Tidak ada barang ditemukan.',
                            style: theme.textTheme.titleMedium?.copyWith(
                              color: Colors.grey.shade600,
                            ),
                          ),
                        ],
                      ),
                    )
                  else
                    ListView.builder(
                      shrinkWrap: true,
                      physics: const NeverScrollableScrollPhysics(),
                      itemCount: widget.inventoryService.items.length,
                      itemBuilder: (context, index) {
                        final item = widget.inventoryService.items[index];

                        return Card(
                          elevation: 1,
                          margin: const EdgeInsets.only(bottom: 12),
                          shape: RoundedRectangleBorder(
                            borderRadius: BorderRadius.circular(12),
                          ),
                          child: InkWell(
                            borderRadius: BorderRadius.circular(12),
                            onTap: () => _openItemMovements(item),
                            child: Padding(
                              padding: const EdgeInsets.all(16),
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Row(
                                    crossAxisAlignment:
                                        CrossAxisAlignment.start,
                                    children: [
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment:
                                              CrossAxisAlignment.start,
                                          children: [
                                            Text(
                                              item.name,
                                              style: const TextStyle(
                                                fontWeight: FontWeight.bold,
                                                fontSize: 16,
                                              ),
                                            ),
                                            if (item.sku != null &&
                                                item.sku!.isNotEmpty)
                                              Text(
                                                'SKU: ${item.sku}',
                                                style: TextStyle(
                                                  color: Colors.grey.shade600,
                                                  fontSize: 13,
                                                ),
                                              ),
                                          ],
                                        ),
                                      ),
                                      if (item.isNegative)
                                        Container(
                                          padding: const EdgeInsets.symmetric(
                                            horizontal: 8,
                                            vertical: 2,
                                          ),
                                          decoration: BoxDecoration(
                                            color: Colors.deepOrange.shade50,
                                            borderRadius: BorderRadius.circular(
                                              6,
                                            ),
                                            border: Border.all(
                                              color: Colors.deepOrange.shade300,
                                            ),
                                          ),
                                          child: const Text(
                                            'MINUS',
                                            style: TextStyle(
                                              color: Colors.deepOrange,
                                              fontSize: 11,
                                              fontWeight: FontWeight.bold,
                                            ),
                                          ),
                                        )
                                      else if (item.isLowStock)
                                        Container(
                                          padding: const EdgeInsets.symmetric(
                                            horizontal: 8,
                                            vertical: 2,
                                          ),
                                          decoration: BoxDecoration(
                                            color: Colors.orange.shade50,
                                            borderRadius: BorderRadius.circular(
                                              6,
                                            ),
                                            border: Border.all(
                                              color: Colors.orange.shade300,
                                            ),
                                          ),
                                          child: const Text(
                                            'STOK RENDAH',
                                            style: TextStyle(
                                              color: Colors.orange,
                                              fontSize: 11,
                                              fontWeight: FontWeight.bold,
                                            ),
                                          ),
                                        ),
                                    ],
                                  ),
                                  const SizedBox(height: 12),

                                  // Three Distinct Quantities Row: On Hand, Reserved, Available
                                  Container(
                                    padding: const EdgeInsets.all(12),
                                    decoration: BoxDecoration(
                                      color: Colors.grey.shade50,
                                      borderRadius: BorderRadius.circular(8),
                                      border: Border.all(
                                        color: Colors.grey.shade200,
                                      ),
                                    ),
                                    child: Row(
                                      mainAxisAlignment:
                                          MainAxisAlignment.spaceAround,
                                      children: [
                                        _QuantityColumn(
                                          label: 'Fisik (On Hand)',
                                          value:
                                              '${item.effectiveOnHand} ${item.baseUnit}',
                                          color: Colors.black87,
                                        ),
                                        Container(
                                          height: 30,
                                          width: 1,
                                          color: Colors.grey.shade300,
                                        ),
                                        _QuantityColumn(
                                          label: 'Dicadangkan',
                                          value:
                                              '${item.effectiveReserved} ${item.baseUnit}',
                                          color: Colors.orange.shade800,
                                        ),
                                        Container(
                                          height: 30,
                                          width: 1,
                                          color: Colors.grey.shade300,
                                        ),
                                        _QuantityColumn(
                                          label: 'Tersedia Jual',
                                          value:
                                              '${item.effectiveAvailable} ${item.baseUnit}',
                                          color: Colors.green.shade700,
                                        ),
                                      ],
                                    ),
                                  ),
                                  const SizedBox(height: 12),

                                  // Action Row
                                  Row(
                                    mainAxisAlignment:
                                        MainAxisAlignment.spaceBetween,
                                    children: [
                                      TextButton.icon(
                                        onPressed: () =>
                                            _openItemMovements(item),
                                        icon: const Icon(
                                          Icons.history,
                                          size: 16,
                                        ),
                                        label: const Text('Riwayat Mutasi'),
                                      ),
                                      ElevatedButton.icon(
                                        onPressed: () =>
                                            _openStockAdjustment(item: item),
                                        icon: const Icon(Icons.tune, size: 16),
                                        label: const Text('Sesuaikan'),
                                        style: ElevatedButton.styleFrom(
                                          backgroundColor: const Color(
                                            0xFF6366F1,
                                          ),
                                          foregroundColor: Colors.white,
                                          padding: const EdgeInsets.symmetric(
                                            horizontal: 14,
                                            vertical: 8,
                                          ),
                                          shape: RoundedRectangleBorder(
                                            borderRadius: BorderRadius.circular(
                                              8,
                                            ),
                                          ),
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
                    ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }
}

class _KpiCard extends StatelessWidget {
  final String title;
  final String value;
  final IconData icon;
  final Color color;
  final bool isActive;
  final VoidCallback? onTap;

  const _KpiCard({
    required this.title,
    required this.value,
    required this.icon,
    required this.color,
    this.isActive = false,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        padding: const EdgeInsets.all(16),
        decoration: BoxDecoration(
          color: isActive ? color.withValues(alpha: 0.12) : Colors.white,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: isActive ? color : Colors.grey.shade200,
            width: isActive ? 2 : 1,
          ),
          boxShadow: [
            BoxShadow(
              color: Colors.black.withValues(alpha: 0.03),
              blurRadius: 6,
              offset: const Offset(0, 2),
            ),
          ],
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  title,
                  style: TextStyle(
                    fontSize: 12,
                    color: Colors.grey.shade600,
                    fontWeight: FontWeight.w500,
                  ),
                ),
                Icon(icon, size: 20, color: color),
              ],
            ),
            const SizedBox(height: 8),
            Text(
              value,
              style: TextStyle(
                fontSize: 22,
                fontWeight: FontWeight.bold,
                color: color,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _QuantityColumn extends StatelessWidget {
  final String label;
  final String value;
  final Color color;

  const _QuantityColumn({
    required this.label,
    required this.value,
    required this.color,
  });

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        Text(
          label,
          style: TextStyle(
            fontSize: 11,
            color: Colors.grey.shade600,
            fontWeight: FontWeight.w500,
          ),
        ),
        const SizedBox(height: 4),
        Text(
          value,
          style: TextStyle(
            fontSize: 13,
            fontWeight: FontWeight.bold,
            color: color,
          ),
        ),
      ],
    );
  }
}
