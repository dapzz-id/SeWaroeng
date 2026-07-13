// Neura Cakrawira Solusi | SeWaroeng
// POS Bluetooth Printer Service (using pdf and printing packages)

import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:pdf/pdf.dart';
import 'package:pdf/widgets.dart' as pw;
import 'package:printing/printing.dart';
import '../../data/models/pos_transaction_hive_model.dart';
import 'package:intl/intl.dart';

class PosPrinterService {
  Future<void> printReceipt(PosTransactionHiveModel transaction, String storeName) async {
    final pdf = pw.Document();

    // 58mm printer format is typically around 48mm printable width
    final format = PdfPageFormat.roll57;

    pdf.addPage(
      pw.Page(
        pageFormat: format,
        build: (pw.Context context) {
          return pw.Column(
            crossAxisAlignment: pw.CrossAxisAlignment.start,
            children: [
              pw.Center(
                child: pw.Text(storeName, style: pw.TextStyle(fontSize: 16, fontWeight: pw.FontWeight.bold)),
              ),
              pw.SizedBox(height: 8),
              pw.Text('Receipt: ${transaction.id.substring(0, 8)}...'),
              pw.Text('Date: ${DateFormat('dd MMM yyyy HH:mm').format(transaction.createdAt)}'),
              pw.Text('Staff: ${transaction.staffId}'),
              pw.Divider(),
              // Items
              ...transaction.items.map((item) {
                return pw.Row(
                  mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                  children: [
                    pw.Expanded(
                      child: pw.Text('${item.productName} (x${item.quantity})', maxLines: 2),
                    ),
                    pw.Text('Rp ${item.subtotal.toStringAsFixed(0)}'),
                  ],
                );
              }),
              pw.Divider(),
              // Totals
              pw.Row(
                mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                children: [
                  pw.Text('Total', style: pw.TextStyle(fontWeight: pw.FontWeight.bold)),
                  pw.Text('Rp ${transaction.total.toStringAsFixed(0)}', style: pw.TextStyle(fontWeight: pw.FontWeight.bold)),
                ],
              ),
              pw.Row(
                mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                children: [
                  pw.Text('Paid (${transaction.method.toUpperCase()})'),
                  pw.Text('Rp ${transaction.amountPaid.toStringAsFixed(0)}'),
                ],
              ),
              if (transaction.changeDue > 0)
                pw.Row(
                  mainAxisAlignment: pw.MainAxisAlignment.spaceBetween,
                  children: [
                    pw.Text('Change'),
                    pw.Text('Rp ${transaction.changeDue.toStringAsFixed(0)}'),
                  ],
                ),
              pw.SizedBox(height: 16),
              pw.Center(child: pw.Text('Terima Kasih!')),
            ],
          );
        },
      ),
    );

    // Use printing package to show print dialog (which handles Bluetooth/WiFi printers natively on Android/iOS)
    await Printing.layoutPdf(
      onLayout: (PdfPageFormat format) async => pdf.save(),
      name: 'Receipt_${transaction.id}',
    );
  }
}

final posPrinterServiceProvider = Provider<PosPrinterService>((ref) {
  return PosPrinterService();
});
