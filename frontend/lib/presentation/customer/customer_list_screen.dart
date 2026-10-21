import 'package:flutter/material.dart';
import '../../core/customer/customer_models.dart';
import '../../core/customer/customer_service.dart';

class CustomerListScreen extends StatefulWidget {
  final CustomerService customerService;

  const CustomerListScreen({super.key, required this.customerService});

  @override
  State<CustomerListScreen> createState() => _CustomerListScreenState();
}

class _CustomerListScreenState extends State<CustomerListScreen> {
  final _searchController = TextEditingController();

  @override
  void initState() {
    super.initState();
    widget.customerService.addListener(_onServiceChanged);
    widget.customerService.fetchCustomers();
  }

  @override
  void dispose() {
    widget.customerService.removeListener(_onServiceChanged);
    _searchController.dispose();
    super.dispose();
  }

  void _onServiceChanged() {
    if (mounted) setState(() {});
  }

  void _onSearch() {
    widget.customerService.fetchCustomers(search: _searchController.text.trim());
  }

  @override
  Widget build(BuildContext context) {
    final service = widget.customerService;
    final customers = service.customers;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Customer CRM & 360'),
        actions: [
          IconButton(
            icon: const Icon(Icons.refresh),
            onPressed: () => service.fetchCustomers(search: _searchController.text.trim()),
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
                hintText: 'Cari pelanggan (nama, nomor telepon, email)...',
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
            child: service.isLoading && customers.isEmpty
                ? const Center(child: CircularProgressIndicator())
                : customers.isEmpty
                    ? Center(
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(Icons.people_outline, size: 64, color: Colors.grey.shade400),
                            const SizedBox(height: 16),
                            const Text(
                              'Belum ada data pelanggan',
                              style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                            ),
                            const SizedBox(height: 8),
                            const Text(
                              'Tambahkan data pelanggan untuk mulai mencatat histori dan loyalty.',
                              style: TextStyle(color: Colors.grey),
                            ),
                          ],
                        ),
                      )
                    : ListView.separated(
                        padding: const EdgeInsets.symmetric(horizontal: 16),
                        itemCount: customers.length,
                        separatorBuilder: (_, _) => const SizedBox(height: 12),
                        itemBuilder: (context, index) {
                          final c = customers[index];
                          return _buildCustomerCard(c);
                        },
                      ),
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _showAddCustomerDialog,
        icon: const Icon(Icons.person_add),
        label: const Text('Tambah Pelanggan'),
      ),
    );
  }

  Widget _buildCustomerCard(CustomerModel customer) {
    Color segmentColor;
    switch (customer.segment.toLowerCase()) {
      case 'vip':
        segmentColor = Colors.amber.shade800;
        break;
      case 'loyal':
        segmentColor = Colors.green;
        break;
      case 'churn_risk':
        segmentColor = Colors.red;
        break;
      default:
        segmentColor = Colors.blue;
    }

    return Card(
      elevation: 1,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: () => _showCustomerDetailSheet(customer),
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  CircleAvatar(
                    backgroundColor: Colors.teal.shade50,
                    child: Text(
                      customer.name.isNotEmpty ? customer.name[0].toUpperCase() : '?',
                      style: TextStyle(color: Colors.teal.shade700, fontWeight: FontWeight.bold),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          customer.name,
                          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                        ),
                        if (customer.phone != null)
                          Text(
                            customer.phone!,
                            style: TextStyle(color: Colors.grey.shade600, fontSize: 13),
                          ),
                      ],
                    ),
                  ),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 4),
                    decoration: BoxDecoration(
                      color: segmentColor.withValues(alpha: 0.1),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: segmentColor),
                    ),
                    child: Text(
                      customer.segment.toUpperCase(),
                      style: TextStyle(color: segmentColor, fontSize: 11, fontWeight: FontWeight.bold),
                    ),
                  ),
                ],
              ),
              if (customer.email != null || customer.notes != null) ...[
                const SizedBox(height: 12),
                if (customer.email != null)
                  Row(
                    children: [
                      Icon(Icons.email_outlined, size: 14, color: Colors.grey.shade600),
                      const SizedBox(width: 6),
                      Text(customer.email!, style: TextStyle(fontSize: 12, color: Colors.grey.shade700)),
                    ],
                  ),
                if (customer.notes != null && customer.notes!.isNotEmpty)
                  Padding(
                    padding: const EdgeInsets.only(top: 4),
                    child: Text(
                      'Catatan: ${customer.notes}',
                      style: TextStyle(fontSize: 12, fontStyle: FontStyle.italic, color: Colors.grey.shade600),
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
            ],
          ),
        ),
      ),
    );
  }

  void _showAddCustomerDialog() {
    final nameCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final emailCtrl = TextEditingController();
    final notesCtrl = TextEditingController();
    bool marketingOptIn = true;

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (ctx, setDialogState) => AlertDialog(
          title: const Text('Tambah Pelanggan'),
          content: SingleChildScrollView(
            child: SizedBox(
              width: 380,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  TextField(
                    controller: nameCtrl,
                    decoration: const InputDecoration(labelText: 'Nama Pelanggan *', isDense: true),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: phoneCtrl,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(labelText: 'Nomor Telepon / WhatsApp', isDense: true),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: emailCtrl,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(labelText: 'Email', isDense: true),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: notesCtrl,
                    maxLines: 2,
                    decoration: const InputDecoration(labelText: 'Catatan Khusus (preferensi, alergi, dsb)', isDense: true),
                  ),
                  const SizedBox(height: 12),
                  CheckboxListTile(
                    title: const Text('Izinkan pesan WhatsApp / promosi'),
                    value: marketingOptIn,
                    onChanged: (val) => setDialogState(() => marketingOptIn = val ?? false),
                    contentPadding: EdgeInsets.zero,
                  ),
                ],
              ),
            ),
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(ctx), child: const Text('Batal')),
            FilledButton(
              onPressed: () async {
                if (nameCtrl.text.trim().isEmpty) return;
                final nav = Navigator.of(ctx);
                final res = await widget.customerService.createCustomer(
                  name: nameCtrl.text.trim(),
                  phone: phoneCtrl.text.trim().isNotEmpty ? phoneCtrl.text.trim() : null,
                  email: emailCtrl.text.trim().isNotEmpty ? emailCtrl.text.trim() : null,
                  notes: notesCtrl.text.trim().isNotEmpty ? notesCtrl.text.trim() : null,
                  marketingOptIn: marketingOptIn,
                );
                if (res != null && mounted) {
                  nav.pop();
                  ScaffoldMessenger.of(context).showSnackBar(
                    const SnackBar(content: Text('Pelanggan berhasil ditambahkan')),
                  );
                }
              },
              child: const Text('Simpan'),
            ),
          ],
        ),
      ),
    );
  }

  String _formatRupiah(int amountMinor) {
    final chars = amountMinor.toString().split('').reversed.toList();
    final buffer = StringBuffer();
    for (int i = 0; i < chars.length; i++) {
      if (i > 0 && i % 3 == 0) buffer.write('.');
      buffer.write(chars[i]);
    }
    return 'Rp ${buffer.toString().split('').reversed.join('')}';
  }

  void _showCustomerDetailSheet(CustomerModel customer) {
    showModalBottomSheet(
      context: context,
      isScrollControlled: true,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(16)),
      ),
      builder: (ctx) => FutureBuilder<List<dynamic>>(
        future: Future.wait([
          widget.customerService.getCustomerSummary(customer.id),
          widget.customerService.getCustomerHistory(customer.id),
        ]),
        builder: (context, snapshot) {
          final isLoading = snapshot.connectionState == ConnectionState.waiting;
          final CustomerSummaryModel? summary =
              snapshot.data != null ? snapshot.data![0] as CustomerSummaryModel? : null;
          final List<CustomerHistoryItemModel> history = snapshot.data != null
              ? (snapshot.data![1] as List<CustomerHistoryItemModel>? ?? [])
              : [];

          final displaySegment = summary?.segment ?? customer.segment;

          return Padding(
            padding: const EdgeInsets.all(24),
            child: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Row(
                    children: [
                      CircleAvatar(
                        radius: 28,
                        backgroundColor: Colors.teal.shade50,
                        child: Text(
                          customer.name.isNotEmpty ? customer.name[0].toUpperCase() : '?',
                          style: TextStyle(
                            fontSize: 22,
                            color: Colors.teal.shade800,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                      const SizedBox(width: 16),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              customer.name,
                              style: const TextStyle(fontSize: 18, fontWeight: FontWeight.bold),
                            ),
                            if (customer.phone != null)
                              Text(customer.phone!, style: const TextStyle(color: Colors.grey)),
                            if (customer.email != null)
                              Text(
                                customer.email!,
                                style: const TextStyle(color: Colors.grey, fontSize: 12),
                              ),
                          ],
                        ),
                      ),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                        decoration: BoxDecoration(
                          color: Colors.teal.shade50,
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(color: Colors.teal),
                        ),
                        child: Text(
                          displaySegment.toUpperCase(),
                          style: TextStyle(
                            color: Colors.teal.shade800,
                            fontSize: 11,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                    ],
                  ),
                  const Divider(height: 28),
                  const Text(
                    'Customer 360 & Metrik RFM',
                    style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16),
                  ),
                  const SizedBox(height: 12),
                  if (isLoading)
                    const Center(
                      child: Padding(
                        padding: EdgeInsets.all(24.0),
                        child: CircularProgressIndicator(),
                      ),
                    )
                  else ...[
                    // RFM Metrics Grid
                    Container(
                      padding: const EdgeInsets.all(16),
                      decoration: BoxDecoration(
                        color: Colors.grey.shade50,
                        borderRadius: BorderRadius.circular(12),
                        border: Border.all(color: Colors.grey.shade200),
                      ),
                      child: Column(
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceAround,
                            children: [
                              _buildStatItem(
                                'Total Belanja',
                                summary != null ? _formatRupiah(summary.totalSpendMinor) : 'Rp 0',
                              ),
                              _buildStatItem(
                                'Transaksi Selesai',
                                '${summary?.successfulTransactions ?? 0}',
                              ),
                              _buildStatItem(
                                'Rata-rata Order',
                                summary != null
                                    ? _formatRupiah(summary.averageOrderValueMinor)
                                    : 'Rp 0',
                              ),
                            ],
                          ),
                          const Divider(height: 20),
                          Row(
                            mainAxisAlignment: MainAxisAlignment.spaceAround,
                            children: [
                              _buildStatItem(
                                'Status Akun',
                                customer.isActive ? 'Aktif' : 'Non-aktif',
                              ),
                              _buildStatItem(
                                'Izin WhatsApp',
                                customer.marketingOptIn ? 'Opt-In' : 'Opt-Out',
                              ),
                              _buildStatItem(
                                'Pembelian Terakhir',
                                summary?.lastPurchaseAt != null
                                    ? '${summary!.lastPurchaseAt!.day}/${summary.lastPurchaseAt!.month}/${summary.lastPurchaseAt!.year}'
                                    : 'Belum Ada',
                              ),
                            ],
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 16),
                    const Text(
                      'Riwayat Pembelian Terakhir',
                      style: TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                    ),
                    const SizedBox(height: 8),
                    if (history.isEmpty)
                      Container(
                        padding: const EdgeInsets.all(16),
                        width: double.infinity,
                        decoration: BoxDecoration(
                          color: Colors.grey.shade50,
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: Colors.grey.shade200),
                        ),
                        child: const Text(
                          'Belum ada riwayat transaksi yang tercatat.',
                          style: TextStyle(color: Colors.grey, fontSize: 13),
                          textAlign: TextAlign.center,
                        ),
                      )
                    else
                      ListView.separated(
                        shrinkWrap: true,
                        physics: const NeverScrollableScrollPhysics(),
                        itemCount: history.length > 5 ? 5 : history.length,
                        separatorBuilder: (_, _) => const SizedBox(height: 6),
                        itemBuilder: (ctx, idx) {
                          final item = history[idx];
                          final dateStr = item.finalizedAt != null
                              ? '${item.finalizedAt!.day}/${item.finalizedAt!.month}/${item.finalizedAt!.year}'
                              : '-';
                          return Container(
                            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
                            decoration: BoxDecoration(
                              color: Colors.white,
                              borderRadius: BorderRadius.circular(8),
                              border: Border.all(color: Colors.grey.shade200),
                            ),
                            child: Row(
                              children: [
                                const Icon(Icons.receipt_outlined, size: 18, color: Colors.teal),
                                const SizedBox(width: 8),
                                Expanded(
                                  child: Column(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Text(
                                        item.referenceNumber,
                                        style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                      ),
                                      Text(dateStr, style: const TextStyle(color: Colors.grey, fontSize: 11)),
                                    ],
                                  ),
                                ),
                                Text(
                                  _formatRupiah(item.totalMinor),
                                  style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 13),
                                ),
                              ],
                            ),
                          );
                        },
                      ),
                  ],
                  if (customer.notes != null && customer.notes!.isNotEmpty) ...[
                    const SizedBox(height: 16),
                    Text(
                      'Catatan: ${customer.notes}',
                      style: TextStyle(color: Colors.grey.shade800, fontSize: 13),
                    ),
                  ],
                  const SizedBox(height: 24),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton(
                          onPressed: () => Navigator.pop(ctx),
                          child: const Text('Tutup'),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _buildStatItem(String label, String value) {
    return Column(
      children: [
        Text(label, style: TextStyle(fontSize: 12, color: Colors.grey.shade600)),
        const SizedBox(height: 4),
        Text(value, style: const TextStyle(fontSize: 15, fontWeight: FontWeight.bold)),
      ],
    );
  }
}
