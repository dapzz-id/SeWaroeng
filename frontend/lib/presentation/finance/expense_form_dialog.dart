import 'package:flutter/material.dart';
import 'package:frontend/core/finance/finance_service.dart';

class ExpenseFormDialog extends StatefulWidget {
  final FinanceService financeService;
  final String? outletId;
  final VoidCallback? onSuccess;

  const ExpenseFormDialog({
    super.key,
    required this.financeService,
    this.outletId,
    this.onSuccess,
  });

  @override
  State<ExpenseFormDialog> createState() => _ExpenseFormDialogState();
}

class _ExpenseFormDialogState extends State<ExpenseFormDialog> {
  final _formKey = GlobalKey<FormState>();
  final _amountController = TextEditingController();
  final _descController = TextEditingController();
  final _refController = TextEditingController();

  String _category = 'utilities';
  String _paymentMethod = 'cash';
  DateTime _selectedDate = DateTime.now();
  bool _isSubmitting = false;

  final Map<String, String> _categoryOptions = {
    'utilities': 'Utilitas & Listrik',
    'supplies': 'Bahan & Perlengkapan',
    'rent': 'Sewa Tempat',
    'salary': 'Gaji & Upah',
    'maintenance': 'Pemeliharaan',
    'marketing': 'Pemasaran',
    'other': 'Lain-lain',
  };

  @override
  void dispose() {
    _amountController.dispose();
    _descController.dispose();
    _refController.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final rawAmount = _amountController.text.replaceAll(RegExp(r'[^0-9]'), '');
    final amountMinor = int.tryParse(rawAmount) ?? 0;

    if (amountMinor <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Nominal pengeluaran harus lebih besar dari 0.')),
      );
      return;
    }

    setState(() => _isSubmitting = true);

    final dateStr = '${_selectedDate.year.toString().padLeft(4, '0')}-${_selectedDate.month.toString().padLeft(2, '0')}-${_selectedDate.day.toString().padLeft(2, '0')}';

    final result = await widget.financeService.createExpense(
      outletId: widget.outletId,
      category: _category,
      amountMinor: amountMinor,
      expenseDate: dateStr,
      paymentMethod: _paymentMethod,
      description: _descController.text.trim().isEmpty ? null : _descController.text.trim(),
      referenceNumber: _refController.text.trim().isEmpty ? null : _refController.text.trim(),
      status: 'posted',
    );

    setState(() => _isSubmitting = false);

    if (result != null && mounted) {
      Navigator.of(context).pop();
      widget.onSuccess?.call();
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Beban ${_categoryOptions[_category]} berhasil diposting.')),
      );
    } else if (mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text(widget.financeService.errorMessage ?? 'Gagal mencatat pengeluaran.')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Catat Pengeluaran Operasional'),
      content: SingleChildScrollView(
        child: SizedBox(
          width: 400,
          child: Form(
            key: _formKey,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                DropdownButtonFormField<String>(
                  initialValue: _category,
                  decoration: const InputDecoration(
                    labelText: 'Kategori Pengeluaran',
                    prefixIcon: Icon(Icons.category),
                    border: OutlineInputBorder(),
                  ),
                  items: _categoryOptions.entries.map((e) {
                    return DropdownMenuItem(value: e.key, child: Text(e.value));
                  }).toList(),
                  onChanged: (val) {
                    if (val != null) setState(() => _category = val);
                  },
                ),
                const SizedBox(height: 16),
                TextFormField(
                  key: const Key('expense_amount_input'),
                  controller: _amountController,
                  keyboardType: TextInputType.number,
                  decoration: const InputDecoration(
                    labelText: 'Nominal (Rp)',
                    prefixIcon: Icon(Icons.money),
                    prefixText: 'Rp ',
                    border: OutlineInputBorder(),
                  ),
                  validator: (val) {
                    if (val == null || val.trim().isEmpty) return 'Nominal wajib diisi';
                    final num = int.tryParse(val.replaceAll(RegExp(r'[^0-9]'), ''));
                    if (num == null || num <= 0) return 'Nominal harus lebih dari 0';
                    return null;
                  },
                ),
                const SizedBox(height: 16),
                const Text('Metode Pembayaran:', style: TextStyle(fontWeight: FontWeight.bold)),
                const SizedBox(height: 8),
                SizedBox(
                  width: double.infinity,
                  child: SegmentedButton<String>(
                    segments: const [
                      ButtonSegment<String>(
                        value: 'cash',
                        label: Text('Kas Tunai'),
                        icon: Icon(Icons.money),
                      ),
                      ButtonSegment<String>(
                        value: 'bank',
                        label: Text('Transfer Bank'),
                        icon: Icon(Icons.account_balance),
                      ),
                    ],
                    selected: {_paymentMethod},
                    onSelectionChanged: (newSelection) {
                      setState(() => _paymentMethod = newSelection.first);
                    },
                  ),
                ),
                const SizedBox(height: 12),
                ListTile(
                  contentPadding: EdgeInsets.zero,
                  leading: const Icon(Icons.calendar_today),
                  title: Text('Tanggal: ${_selectedDate.day.toString().padLeft(2, '0')}-${_selectedDate.month.toString().padLeft(2, '0')}-${_selectedDate.year}'),
                  trailing: TextButton(
                    onPressed: () async {
                      final picked = await showDatePicker(
                        context: context,
                        initialDate: _selectedDate,
                        firstDate: DateTime(2020),
                        lastDate: DateTime.now(),
                      );
                      if (picked != null) setState(() => _selectedDate = picked);
                    },
                    child: const Text('Ubah'),
                  ),
                ),
                const SizedBox(height: 8),
                TextFormField(
                  controller: _descController,
                  decoration: const InputDecoration(
                    labelText: 'Keterangan / Keperluan',
                    prefixIcon: Icon(Icons.notes),
                    border: OutlineInputBorder(),
                  ),
                  maxLines: 2,
                ),
                const SizedBox(height: 12),
                TextFormField(
                  controller: _refController,
                  decoration: const InputDecoration(
                    labelText: 'No. Bukti / Referensi (Opsional)',
                    prefixIcon: Icon(Icons.receipt),
                    border: OutlineInputBorder(),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _isSubmitting ? null : () => Navigator.of(context).pop(),
          child: const Text('Batal'),
        ),
        ElevatedButton(
          key: const Key('expense_submit_button'),
          onPressed: _isSubmitting ? null : _submit,
          child: _isSubmitting
              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
              : const Text('Posting Beban'),
        ),
      ],
    );
  }
}
