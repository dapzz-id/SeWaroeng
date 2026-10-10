import 'package:flutter/material.dart';
import '../../core/catalog/catalog_service.dart';

class CategoryManagementSheet extends StatefulWidget {
  final CatalogService catalogService;

  const CategoryManagementSheet({super.key, required this.catalogService});

  @override
  State<CategoryManagementSheet> createState() =>
      _CategoryManagementSheetState();
}

class _CategoryManagementSheetState extends State<CategoryManagementSheet> {
  final _nameController = TextEditingController();
  final _slugController = TextEditingController();
  String? _selectedParentId;
  bool _isCreating = false;

  @override
  void initState() {
    super.initState();
    widget.catalogService.fetchCategories();
  }

  @override
  void dispose() {
    _nameController.dispose();
    _slugController.dispose();
    super.dispose();
  }

  Future<void> _submitCategory() async {
    final name = _nameController.text.trim();
    if (name.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Nama kategori tidak boleh kosong')),
      );
      return;
    }

    setState(() => _isCreating = true);

    final res = await widget.catalogService.createCategory(
      name: name,
      slug: _slugController.text.trim().isEmpty
          ? null
          : _slugController.text.trim(),
      parentId: _selectedParentId,
    );

    setState(() => _isCreating = false);

    if (res != null && mounted) {
      _nameController.clear();
      _slugController.clear();
      _selectedParentId = null;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Kategori "${res.name}" berhasil dibuat')),
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
        final categories = widget.catalogService.categories;

        return Container(
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
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  const Text(
                    'Kelola Kategori',
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
              const SizedBox(height: 8),

              // Creation Form
              Container(
                padding: const EdgeInsets.all(12),
                decoration: BoxDecoration(
                  color: const Color(0xFFF8FAFC),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: const Color(0xFFE2E8F0)),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    TextField(
                      controller: _nameController,
                      decoration: const InputDecoration(
                        labelText: 'Nama Kategori Baru *',
                        hintText: 'Misal: Kopi, Makanan Ringan',
                        isDense: true,
                        border: OutlineInputBorder(),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Row(
                      children: [
                        Expanded(
                          child: DropdownButtonFormField<String?>(
                            initialValue: _selectedParentId,
                            isExpanded: true,
                            decoration: const InputDecoration(
                              labelText: 'Induk Kategori (Opsional)',
                              isDense: true,
                              border: OutlineInputBorder(),
                            ),
                            items: [
                              const DropdownMenuItem(
                                value: null,
                                child: Text(
                                  '(Kategori Utama)',
                                  style: TextStyle(color: Colors.grey),
                                ),
                              ),
                              ...categories.map(
                                (c) => DropdownMenuItem(
                                  value: c.id,
                                  child: Text(
                                    c.name,
                                    overflow: TextOverflow.ellipsis,
                                  ),
                                ),
                              ),
                            ],
                            onChanged: (val) =>
                                setState(() => _selectedParentId = val),
                          ),
                        ),
                        const SizedBox(width: 8),
                        ElevatedButton.icon(
                          onPressed: _isCreating ? null : _submitCategory,
                          icon: _isCreating
                              ? const SizedBox(
                                  width: 16,
                                  height: 16,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                    color: Colors.white,
                                  ),
                                )
                              : const Icon(Icons.add, size: 18),
                          label: const Text('Simpan'),
                          style: ElevatedButton.styleFrom(
                            backgroundColor: const Color(0xFF0F766E),
                            foregroundColor: Colors.white,
                            padding: const EdgeInsets.symmetric(
                              horizontal: 16,
                              vertical: 14,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ],
                ),
              ),

              const SizedBox(height: 16),
              const Text(
                'Daftar Kategori Aktif',
                style: TextStyle(
                  fontSize: 14,
                  fontWeight: FontWeight.w600,
                  color: Color(0xFF475569),
                ),
              ),
              const SizedBox(height: 8),

              // Categories List
              Flexible(
                child: categories.isEmpty
                    ? const Padding(
                        padding: EdgeInsets.symmetric(vertical: 24),
                        child: Center(
                          child: Text(
                            'Belum ada kategori.',
                            style: TextStyle(color: Colors.grey),
                          ),
                        ),
                      )
                    : ListView.separated(
                        shrinkWrap: true,
                        itemCount: categories.length,
                        separatorBuilder: (_, _) => const Divider(height: 1),
                        itemBuilder: (context, index) {
                          final cat = categories[index];
                          final isChild = cat.parentId != null;

                          return ListTile(
                            dense: true,
                            contentPadding: EdgeInsets.only(
                              left: isChild ? 24 : 8,
                              right: 8,
                            ),
                            leading: Icon(
                              isChild
                                  ? Icons.subdirectory_arrow_right
                                  : Icons.folder_outlined,
                              size: 18,
                              color: isChild
                                  ? const Color(0xFF64748B)
                                  : const Color(0xFF0F766E),
                            ),
                            title: Text(
                              cat.name,
                              style: TextStyle(
                                fontWeight: isChild
                                    ? FontWeight.normal
                                    : FontWeight.w600,
                                color: const Color(0xFF1E293B),
                              ),
                            ),
                            subtitle: Text('slug: ${cat.slug}'),
                            trailing: Chip(
                              label: Text(
                                cat.status,
                                style: const TextStyle(
                                  fontSize: 10,
                                  color: Colors.white,
                                ),
                              ),
                              backgroundColor: cat.isActive
                                  ? const Color(0xFF10B981)
                                  : Colors.grey,
                              visualDensity: VisualDensity.compact,
                              padding: EdgeInsets.zero,
                            ),
                          );
                        },
                      ),
              ),
            ],
          ),
        );
      },
    );
  }
}
