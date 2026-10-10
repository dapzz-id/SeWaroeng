import 'package:flutter/material.dart';
import 'package:frontend/core/receipt/receipt_models.dart';
import 'package:frontend/core/receipt/receipt_service.dart';
import 'package:frontend/core/whatsapp/whatsapp_service.dart';

class ReceiptScreen extends StatefulWidget {
  final String saleId;
  final ReceiptService receiptService;
  final WhatsAppService? whatsAppService;

  const ReceiptScreen({
    super.key,
    required this.saleId,
    required this.receiptService,
    this.whatsAppService,
  });

  @override
  State<ReceiptScreen> createState() => _ReceiptScreenState();
}

class _ReceiptScreenState extends State<ReceiptScreen> {
  ReceiptModel? _receipt;
  bool _isLoading = true;
  bool _isReprinting = false;
  bool _isSendingWhatsApp = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadReceipt();
  }

  Future<void> _loadReceipt() async {
    setState(() {
      _isLoading = true;
      _error = null;
    });

    final receipt = await widget.receiptService.getReceipt(widget.saleId);
    if (!mounted) return;

    setState(() {
      _receipt = receipt;
      _isLoading = false;
      _error = receipt == null ? (widget.receiptService.errorMessage ?? 'Struk tidak tersedia.') : null;
    });
  }

  Future<void> _handleReprint() async {
    setState(() {
      _isReprinting = true;
    });

    final reprinted = await widget.receiptService.reprintReceipt(widget.saleId);
    if (!mounted) return;

    setState(() {
      _isReprinting = false;
      if (reprinted != null) {
        _receipt = reprinted;
      }
    });

    if (reprinted != null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text('Struk berhasil dicetak ulang (Cetak ke-${reprinted.reprintCount})'),
          backgroundColor: Colors.green,
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(widget.receiptService.errorMessage ?? 'Gagal mencetak ulang.'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  Future<void> _handleSendWhatsApp() async {
    if (widget.whatsAppService == null) return;

    final phone = _receipt?.customerPhone;
    if (phone == null || phone.trim().isEmpty) {
      final enteredPhone = await _showPhoneInputDialog();
      if (enteredPhone == null || enteredPhone.trim().isEmpty) return;
      await _sendWhatsAppReceipt(enteredPhone.trim());
    } else {
      await _sendWhatsAppReceipt(phone.trim());
    }
  }

  Future<String?> _showPhoneInputDialog() async {
    final controller = TextEditingController();
    return showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Kirim Nota ke WhatsApp'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Nomor WhatsApp pelanggan belum tercatat. Masukkan nomor tujuan:',
              style: TextStyle(fontSize: 13),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: controller,
              keyboardType: TextInputType.phone,
              autofocus: true,
              decoration: const InputDecoration(
                labelText: 'Nomor WhatsApp',
                hintText: '081234567890',
                border: OutlineInputBorder(),
                prefixIcon: Icon(Icons.phone),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, null),
            child: const Text('Batal'),
          ),
          ElevatedButton(
            onPressed: () => Navigator.pop(ctx, controller.text),
            child: const Text('Kirim'),
          ),
        ],
      ),
    );
  }

  Future<void> _sendWhatsAppReceipt(String phone) async {
    setState(() => _isSendingWhatsApp = true);
    final success = await widget.whatsAppService!.sendReceipt(
      saleId: widget.saleId,
      phone: phone,
    );
    if (!mounted) return;
    setState(() => _isSendingWhatsApp = false);

    if (success) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Nota transaksi berhasil dikirim ke WhatsApp pelanggan.'),
          backgroundColor: Colors.green,
        ),
      );
    } else {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(widget.whatsAppService!.errorMessage ?? 'Gagal mengirim nota ke WhatsApp.'),
          backgroundColor: Colors.red,
        ),
      );
    }
  }

  String _formatRupiah(int minor) {
    final str = minor.toString();
    final buffer = StringBuffer();
    for (int i = 0; i < str.length; i++) {
      if (i > 0 && (str.length - i) % 3 == 0) {
        buffer.write('.');
      }
      buffer.write(str[i]);
    }
    return 'Rp $buffer';
  }

  String _formatDateTime(DateTime? dt) {
    if (dt == null) return '-';
    final d = dt.toLocal();
    final day = d.day.toString().padLeft(2, '0');
    final month = d.month.toString().padLeft(2, '0');
    final year = d.year.toString();
    final hour = d.hour.toString().padLeft(2, '0');
    final minute = d.minute.toString().padLeft(2, '0');
    return '$day/$month/$year $hour:$minute';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: Colors.grey.shade100,
      appBar: AppBar(
        title: const Text('Struk Transaksi'),
        backgroundColor: Colors.white,
        foregroundColor: Colors.black87,
        elevation: 1,
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: _isLoading ? null : _loadReceipt,
            tooltip: 'Muat Ulang',
          ),
        ],
      ),
      body: _buildBody(),
      bottomNavigationBar: _buildBottomActions(),
    );
  }

  Widget _buildBody() {
    if (_isLoading) {
      return const Center(
        child: Column(
          mainAxisAlignment: MainAxisAlignment.center,
          children: [
            CircularProgressIndicator(),
            SizedBox(height: 16),
            Text('Memuat struk transaksi...'),
          ],
        ),
      );
    }

    if (_error != null) {
      return Center(
        child: Padding(
          padding: const EdgeInsets.all(24.0),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              const Icon(Icons.error_outline, size: 48, color: Colors.red),
              const SizedBox(height: 16),
              Text(
                _error!,
                textAlign: TextAlign.center,
                style: const TextStyle(fontSize: 16, color: Colors.black87),
              ),
              const SizedBox(height: 16),
              ElevatedButton.icon(
                onPressed: _loadReceipt,
                icon: const Icon(Icons.refresh),
                label: const Text('Coba Lagi'),
              ),
            ],
          ),
        ),
      );
    }

    if (_receipt == null) {
      return const Center(child: Text('Struk tidak ditemukan.'));
    }

    final r = _receipt!;

    return SingleChildScrollView(
      padding: const EdgeInsets.symmetric(horizontal: 20, vertical: 16),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 480),
          child: Card(
            elevation: 3,
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
            child: Padding(
              padding: const EdgeInsets.all(24.0),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  // Reprint Banner if applicable
                  if (r.isReprint) ...[
                    Container(
                      padding: const EdgeInsets.symmetric(vertical: 6, horizontal: 12),
                      decoration: BoxDecoration(
                        color: Colors.amber.shade100,
                        borderRadius: BorderRadius.circular(6),
                        border: Border.all(color: Colors.amber.shade400),
                      ),
                      child: Row(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const Icon(Icons.repeat, size: 16, color: Colors.brown),
                          const SizedBox(width: 8),
                          Text(
                            'SALINAN STRUK (Cetak Ulang #${r.reprintCount})',
                            style: const TextStyle(
                              fontSize: 12,
                              fontWeight: FontWeight.bold,
                              color: Colors.brown,
                            ),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                  ],

                  // Header: Business & Outlet info
                  Text(
                    r.businessName,
                    textAlign: TextAlign.center,
                    style: const TextStyle(fontSize: 20, fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 4),
                  Text(
                    r.outletName,
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 14, color: Colors.grey.shade700),
                  ),
                  if (r.outletAddress != null && r.outletAddress!.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      r.outletAddress!,
                      textAlign: TextAlign.center,
                      style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                    ),
                  ],
                  if (r.outletPhone != null && r.outletPhone!.isNotEmpty) ...[
                    const SizedBox(height: 2),
                    Text(
                      r.outletPhone!,
                      textAlign: TextAlign.center,
                      style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
                    ),
                  ],

                  const SizedBox(height: 12),
                  const Divider(thickness: 1),
                  const SizedBox(height: 8),

                  // Metadata: Receipt No, Reference, Cashier, Customer
                  _buildMetaRow('No. Struk', r.receiptNumber, isBold: true),
                  _buildMetaRow('No. Ref Sale', r.saleReferenceNumber),
                  _buildMetaRow('Waktu', _formatDateTime(r.issuedAt)),
                  _buildMetaRow('Kasir', r.cashierName),
                  _buildMetaRow(
                    'Pelanggan',
                    r.customerName != null
                        ? '${r.customerName}${r.customerPhone != null ? ' (${r.customerPhone})' : ''}'
                        : 'Pelanggan Umum (Walk-in)',
                    isAccent: r.customerName != null,
                  ),

                  const SizedBox(height: 8),
                  const Divider(thickness: 1),
                  const SizedBox(height: 8),

                  // Line Items
                  const Text(
                    'RINCIAN PESANAN',
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.grey),
                  ),
                  const SizedBox(height: 8),
                  ...r.items.map((item) => _buildItemRow(item)),

                  const SizedBox(height: 8),
                  const Divider(thickness: 1),
                  const SizedBox(height: 8),

                  // Financial Totals
                  _buildSummaryRow('Subtotal', _formatRupiah(r.subtotalMinor)),
                  if (r.discountMinor > 0)
                    _buildSummaryRow('Diskon', '-${_formatRupiah(r.discountMinor)}', isDiscount: true),
                  if (r.taxMinor > 0)
                    _buildSummaryRow('Pajak', _formatRupiah(r.taxMinor)),
                  const Divider(thickness: 1.5),
                  _buildSummaryRow(
                    'TOTAL',
                    _formatRupiah(r.totalMinor),
                    isTotal: true,
                  ),

                  const SizedBox(height: 12),
                  const Divider(thickness: 1),
                  const SizedBox(height: 8),

                  // Payment Breakdown
                  const Text(
                    'PEMBAYARAN',
                    style: TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.grey),
                  ),
                  const SizedBox(height: 8),
                  if (r.payments.isEmpty)
                    const Text('Belum ada data pembayaran', style: TextStyle(fontStyle: FontStyle.italic, fontSize: 13))
                  else
                    ...r.payments.map((p) => _buildPaymentRow(p)),

                  const SizedBox(height: 16),
                  const Divider(thickness: 1),
                  const SizedBox(height: 12),

                  // Footer
                  Text(
                    'Terima kasih atas kunjungan Anda!\nBarang yang sudah dibeli tidak dapat ditukar.',
                    textAlign: TextAlign.center,
                    style: TextStyle(fontSize: 11, color: Colors.grey.shade600, height: 1.4),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _buildMetaRow(String label, String value, {bool isBold = false, bool isAccent = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: TextStyle(fontSize: 12, color: Colors.grey.shade700)),
          const SizedBox(width: 8),
          Flexible(
            child: Text(
              value,
              textAlign: TextAlign.end,
              style: TextStyle(
                fontSize: 12,
                fontWeight: isBold ? FontWeight.bold : FontWeight.normal,
                color: isAccent ? Colors.deepPurple : Colors.black87,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildItemRow(ReceiptItemModel item) {
    final qtyStr = item.quantity % 1 == 0 ? item.quantity.toInt().toString() : item.quantity.toString();
    final title = item.variantName != null && item.variantName!.isNotEmpty
        ? '${item.name} (${item.variantName})'
        : item.name;

    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Expanded(
                child: Text(
                  title,
                  style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
                ),
              ),
              Text(
                _formatRupiah(item.subtotalMinor),
                style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
              ),
            ],
          ),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                '$qtyStr x ${_formatRupiah(item.unitPriceMinor)}',
                style: TextStyle(fontSize: 11, color: Colors.grey.shade600),
              ),
              if (item.discountMinor > 0)
                Text(
                  '-${_formatRupiah(item.discountMinor)}',
                  style: const TextStyle(fontSize: 11, color: Colors.red),
                ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _buildSummaryRow(String label, String value, {bool isTotal = false, bool isDiscount = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(
            label,
            style: TextStyle(
              fontSize: isTotal ? 15 : 13,
              fontWeight: isTotal ? FontWeight.bold : FontWeight.normal,
              color: isTotal ? Colors.black87 : Colors.grey.shade800,
            ),
          ),
          Text(
            value,
            style: TextStyle(
              fontSize: isTotal ? 16 : 13,
              fontWeight: isTotal ? FontWeight.bold : FontWeight.normal,
              color: isDiscount ? Colors.red : (isTotal ? Colors.deepPurple : Colors.black87),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildPaymentRow(ReceiptPaymentModel payment) {
    return Column(
      children: [
        Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(
              'Metode: ${payment.method.toUpperCase()}',
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
            ),
            Text(
              _formatRupiah(payment.amountMinor),
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w500),
            ),
          ],
        ),
        if (payment.changeMinor > 0) ...[
          const SizedBox(height: 2),
          Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                'Kembalian',
                style: TextStyle(fontSize: 12, color: Colors.grey.shade600),
              ),
              Text(
                _formatRupiah(payment.changeMinor),
                style: const TextStyle(fontSize: 12, fontWeight: FontWeight.bold, color: Colors.green),
              ),
            ],
          ),
        ],
      ],
    );
  }

  Widget _buildBottomActions() {
    if (_receipt == null) return const SizedBox.shrink();

    return Container(
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white,
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.05),
            blurRadius: 8,
            offset: const Offset(0, -2),
          ),
        ],
      ),
      child: Row(
        children: [
          Expanded(
            child: OutlinedButton.icon(
              onPressed: () => Navigator.pop(context),
              icon: const Icon(Icons.arrow_back),
              label: const Text('Tutup'),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.deepPurple,
                foregroundColor: Colors.white,
              ),
              onPressed: _isReprinting ? null : _handleReprint,
              icon: _isReprinting
                  ? const SizedBox(
                      width: 16,
                      height: 16,
                      child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                    )
                  : const Icon(Icons.print),
              label: const Text('Cetak Ulang'),
            ),
          ),
          if (widget.whatsAppService != null) ...[
            const SizedBox(width: 12),
            Expanded(
              child: ElevatedButton.icon(
                style: ElevatedButton.styleFrom(
                  backgroundColor: Colors.teal.shade700,
                  foregroundColor: Colors.white,
                ),
                onPressed: _isSendingWhatsApp ? null : _handleSendWhatsApp,
                icon: _isSendingWhatsApp
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                      )
                    : const Icon(Icons.send_to_mobile),
                label: const Text('WhatsApp'),
              ),
            ),
          ],
        ],
      ),
    );
  }
}
