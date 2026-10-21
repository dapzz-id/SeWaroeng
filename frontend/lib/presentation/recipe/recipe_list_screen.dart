import 'package:flutter/material.dart';
import '../../core/recipe/recipe_models.dart';
import '../../core/recipe/recipe_service.dart';

class RecipeListScreen extends StatefulWidget {
  final RecipeService recipeService;

  const RecipeListScreen({super.key, required this.recipeService});

  @override
  State<RecipeListScreen> createState() => _RecipeListScreenState();
}

class _RecipeListScreenState extends State<RecipeListScreen> {
  final _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    widget.recipeService.addListener(_onServiceChanged);
    widget.recipeService.fetchRecipes();
  }

  @override
  void dispose() {
    widget.recipeService.removeListener(_onServiceChanged);
    _searchController.dispose();
    super.dispose();
  }

  void _onServiceChanged() {
    if (mounted) setState(() {});
  }

  void _onSearch() {
    widget.recipeService.fetchRecipes(search: _searchController.text.trim());
  }

  @override
  Widget build(BuildContext context) {
    final service = widget.recipeService;
    final recipes = service.recipes;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Resep, BOM & HPP'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: () => service.fetchRecipes(search: _searchController.text.trim()),
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
            child: TextField(
              controller: _searchController,
              decoration: InputDecoration(
                hintText: 'Cari resep (nama, kode)...',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: IconButton(
                  icon: const Icon(Icons.clear),
                  onPressed: () {
                    _searchController.clear();
                    _onSearch();
                  },
                ),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                isDense: true,
              ),
              onSubmitted: (_) => _onSearch(),
            ),
          ),
          Expanded(
            child: service.isLoading && recipes.isEmpty
                ? const Center(child: CircularProgressIndicator())
                : recipes.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.menu_book_outlined, size: 64, color: Colors.grey.shade400),
                            const SizedBox(height: 16),
                            const Text(
                              'Belum ada resep terdaftar',
                              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(height: 8),
                            const Text(
                              'Tambahkan resep untuk menghitung HPP otomatis saat penjualan.',
                              style: TextStyle(color: Colors.grey),
                            ),
                          ],
                        ),
                      )
                    : ListView.separated(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        itemCount: recipes.length,
                        separatorBuilder: (_, _) => const SizedBox(height: 12),
                        itemBuilder: (context, index) {
                          final r = recipes[index];
                          return _buildRecipeCard(r);
                        },
                      ),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _showAddRecipeDialog,
        icon: const Icon(Icons.add),
        label: const Text('Tambah Resep'),
      ),
    );
  }

  Widget _buildRecipeCard(RecipeModel recipe) {
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
                CircleAvatar(
                  backgroundColor: Colors.amber.shade50,
                  child: Icon(Icons.restaurant_menu, color: Colors.amber.shade800),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        recipe.name,
                        style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                      ),
                      Text(
                        'Kode: ${recipe.code}',
                        style: TextStyle(color: Colors.grey.shade600, fontSize: 13),
                      ),
                    ],
                  ),
                ),
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                  decoration: BoxDecoration(
                    color: recipe.status == 'active' ? Colors.green.shade50 : Colors.grey.shade100,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(
                      color: recipe.status == 'active' ? Colors.green : Colors.grey,
                    ),
                  ),
                  child: Text(
                    recipe.status == 'active' ? 'Aktif' : 'Arsip',
                    style: TextStyle(
                      color: recipe.status == 'active' ? Colors.green.shade800 : Colors.grey.shade800,
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                ),
              ],
            ),
            const Divider(height: 24),
            Row(
              children: [
                if (recipe.productName != null) ...[
                  Icon(Icons.local_offer_outlined, size: 16, color: Colors.grey.shade600),
                  const SizedBox(width: 6),
                  Text(
                    'Menu: ${recipe.productName}',
                    style: TextStyle(fontSize: 13, color: Colors.grey.shade800),
                  ),
                ] else
                  Text(
                    'Resep Umum / Komponen',
                    style: TextStyle(fontSize: 13, color: Colors.grey.shade600),
                  ),
                const Spacer(),
                FilledButton.tonal(
                  onPressed: () => _showCostSheet(recipe),
                  style: FilledButton.styleFrom(
                    padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 0),
                  ),
                  child: const Text('Hitung HPP', style: TextStyle(fontSize: 12)),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }

  void _showAddRecipeDialog() {
    final nameCtrl = TextEditingController();
    final codeCtrl = TextEditingController();
    String? localError;
    bool isSubmitting = false;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDialogState) => AlertDialog(
          title: const Text('Tambah Resep Baru'),
          content: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (localError != null)
                Container(
                  padding: const EdgeInsets.all(8),
                  margin: const EdgeInsets.only(bottom: 12),
                  decoration: BoxDecoration(
                    color: Colors.red.shade900.withValues(alpha: 0.2),
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: Colors.red.shade700),
                  ),
                  child: Row(
                    children: [
                      const Icon(Icons.error_outline, color: Colors.red, size: 16),
                      const SizedBox(width: 8),
                      Expanded(
                        child: Text(
                          localError!,
                          style: const TextStyle(color: Colors.red, fontSize: 12),
                        ),
                      ),
                    ],
                  ),
                ),
              TextField(
                controller: nameCtrl,
                decoration: const InputDecoration(labelText: 'Nama Resep *', isDense: true),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: codeCtrl,
                decoration: const InputDecoration(labelText: 'Kode Resep (mis. RCP-KOPI-01) *', isDense: true),
              ),
            ],
          ),
          actions: [
            TextButton(
              onPressed: isSubmitting ? null : () => Navigator.pop(ctx),
              child: const Text('Batal'),
            ),
            FilledButton(
              onPressed: isSubmitting
                  ? null
                  : () async {
                      if (nameCtrl.text.trim().isEmpty || codeCtrl.text.trim().isEmpty) {
                        setDialogState(() {
                          localError = 'Nama dan kode resep wajib diisi.';
                        });
                        return;
                      }

                      setDialogState(() {
                        isSubmitting = true;
                        localError = null;
                      });

                      final nav = Navigator.of(ctx);
                      final success = await widget.recipeService.createRecipe(
                        name: nameCtrl.text.trim(),
                        code: codeCtrl.text.trim(),
                      );

                      if (success && mounted && context.mounted) {
                        nav.pop();
                        ScaffoldMessenger.of(context).showSnackBar(
                          const SnackBar(content: Text('Resep berhasil ditambahkan')),
                        );
                      } else {
                        setDialogState(() {
                          isSubmitting = false;
                          localError = widget.recipeService.errorMessage ?? 'Gagal menambahkan resep.';
                        });
                      }
                    },
              child: isSubmitting
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Text('Simpan'),
            ),
          ],
        ),
      ),
    );
  }

  void _showCostSheet(RecipeModel recipe) async {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => FutureBuilder<Map<String, dynamic>?>(
        future: widget.recipeService.calculateRecipeCost(recipe.id),
        builder: (context, snapshot) {
          if (snapshot.connectionState == ConnectionState.waiting) {
            return const SizedBox(
              height: 200,
              child: Center(child: CircularProgressIndicator()),
            );
          }

          final data = snapshot.data;
          final unitCost = data?['unit_cost_minor'] ?? 0;
          final totalCost = data?['total_cost_minor'] ?? unitCost;
          final formattedCost = 'Rp ${(totalCost).toString().replaceAllMapped(RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'), (m) => '${m[1]}.')}';

          return Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(
                        'Estimasi HPP: ${recipe.name}',
                        style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                      ),
                    ),
                    IconButton(
                      icon: const Icon(Icons.close),
                      onPressed: () => Navigator.pop(ctx),
                    ),
                  ],
                ),
                const Divider(height: 24),
                Container(
                  padding: const EdgeInsets.all(16),
                  decoration: BoxDecoration(
                    color: Colors.amber.shade50,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: Colors.amber.shade200),
                  ),
                  child: Row(
                    children: [
                      Icon(Icons.monetization_on, color: Colors.amber.shade900, size: 28),
                      const SizedBox(width: 12),
                      Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          const Text('HPP per Porsi / Batch:', style: TextStyle(fontSize: 13)),
                          Text(
                            formattedCost,
                            style: TextStyle(
                              fontSize: 20,
                              fontWeight: FontWeight.bold,
                              color: Colors.amber.shade900,
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                const Text(
                  'Catatan: HPP dihitung secara dinamis dari FIFO cost layers bahan baku inventory saat ini.',
                  style: TextStyle(color: Colors.grey, fontSize: 12),
                ),
                const SizedBox(height: 20),
                FilledButton(
                  onPressed: () => Navigator.pop(ctx),
                  style: FilledButton.styleFrom(minimumSize: const Size.fromHeight(44)),
                  child: const Text('Tutup'),
                ),
              ],
            ),
          );
        },
      ),
    );
  }
}
