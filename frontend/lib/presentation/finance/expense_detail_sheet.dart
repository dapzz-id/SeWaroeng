import 'package:flutter/material.dart';
import 'package:frontend/core/finance/finance_models.dart';
import 'package:frontend/core/finance/finance_service.dart';

class ExpenseDetailSheet extends StatefulWidget {
  final ExpenseModel expense;
  final FinanceService financeService;
  final VoidCallback? onReversed;

  const ExpenseDetailSheet({
    super.key,
    required this.expense,
    required this.financeService,
    this.onReversed,
  });

  @override
  State<ExpenseDetailSheet> createState() => _ExpenseDetailSheetState();
}

class _ExpenseDetailSheetState extends State<ExpenseDetailSheet> {
  bool _isReversing = false;

  Future<void> _promptReverse() async {
    final reasonController = TextEditingController();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Konfirmasi Pembatalan Beban'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Pembatalan beban akan membuat jurnal pembalik (reversal) pada buku kas/bank dan membatalkan beban ini secara permanen.',
              style: TextStyle(fontSize: 13),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: reasonController,
              decoration: const InputDecoration(
                labelText: 'Alasan Pembatalan',
                border: OutlineInputBorder(),
              ),
              maxLines: 2,
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.of(ctx).pop(false), child: const Text('Batal')),
          ElevatedButton(
            style: ElevatedButton.styleFrom(backgroundColor: Colors.red, foregroundColor: Colors.white),
            onPressed: () {
              if (reasonController.text.trim().isEmpty) return;
              Navigator.of(ctx).pop(true);
            },
            child: const Text('Ya, Batalkan Beban'),
          ),
        ],
      ),
    );

    if (confirmed == true && reasonController.text.trim().isNotEmpty) {
      setState(() => _isReversing = true);
      final ok = await widget.financeService.reverseExpense(
        widget.expense.id,
        reasonController.text.trim(),
      );
      setState(() => _isReversing = false);

      if (ok && mounted) {
        Navigator.of(context).pop();
        widget.onReversed?.call();
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Beban berhasil dibatalkan.')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final exp = widget.expense;

    Color statusColor;
    if (exp.isPosted) {
      statusColor = Colors.green;
    } else if (exp.isReversed) {
      statusColor = Colors.red;
    } else {
      statusColor = Colors.orange;
    }

    return Padding(
      padding: const EdgeInsets.all(20),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                exp.categoryLabel,
                style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
              ),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                decoration: BoxDecoration(
                  color: statusColor.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(color: statusColor),
                ),
                child: Text(
                  exp.statusLabel,
                  style: TextStyle(color: statusColor, fontWeight: FontWeight.bold, fontSize: 12),
                ),
              ),
            ],
          ),
          const SizedBox(height: 12),
          Text(
            exp.formattedAmount,
            style: const TextStyle(fontSize: 24, fontWeight: FontWeight.bold, color: Colors.blueAccent),
          ),
          const Divider(height: 24),
          _detailRow('Metode Pembayaran', exp.paymentMethod == 'cash' ? 'Kas Tunai' : 'Transfer Bank'),
          _detailRow('Tanggal Beban', exp.expenseDate),
          if (exp.description != null) _detailRow('Keterangan', exp.description!),
          if (exp.referenceNumber != null) _detailRow('No. Referensi', exp.referenceNumber!),
          if (exp.accountName != null) _detailRow('Akun Beban', '${exp.accountCode} - ${exp.accountName}'),
          const SizedBox(height: 20),
          if (exp.isPosted)
            SizedBox(
              width: double.infinity,
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.red.shade50,
                  foregroundColor: Colors.red,
                  side: const BorderSide(color: Colors.red),
                ),
                onPressed: _isReversing ? null : _promptReverse,
                icon: const Icon(Icons.undo),
                label: _isReversing
                    ? const SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2))
                    : const Text('Batalkan Beban (Reverse)'),
              ),
            ),
        ],
      ),
    );
  }

  Widget _detailRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(color: Colors.grey, fontSize: 13)),
          Text(value, style: const TextStyle(fontWeight: FontWeight.w500, fontSize: 13)),
        ],
      ),
    );
  }
}
