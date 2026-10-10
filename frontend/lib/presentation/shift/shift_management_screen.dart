import 'package:flutter/material.dart';
import '../../core/shift/shift_models.dart';
import '../../core/shift/shift_service.dart';
import '../../core/tenant/tenant_service.dart';

class ShiftManagementScreen extends StatefulWidget {
  final ShiftService shiftService;
  final TenantService tenantService;

  const ShiftManagementScreen({
    super.key,
    required this.shiftService,
    required this.tenantService,
  });

  @override
  State<ShiftManagementScreen> createState() => _ShiftManagementScreenState();
}

class _ShiftManagementScreenState extends State<ShiftManagementScreen> {
  final TextEditingController _openingCashController = TextEditingController(text: '100000');
  final TextEditingController _openingNotesController = TextEditingController();

  @override
  void initState() {
    super.initState();
    _loadShiftData();
  }

  void _loadShiftData() {
    final outlet = widget.tenantService.activeOutlet;
    if (outlet != null) {
      widget.shiftService.fetchCurrentShift(outlet.id);
    }
  }

  @override
  void dispose() {
    _openingCashController.dispose();
    _openingNotesController.dispose();
    super.dispose();
  }

  String _formatCurrency(int minor) {
    final s = minor.abs().toString();
    final buffer = StringBuffer();
    for (int i = 0; i < s.length; i++) {
      if (i > 0 && (s.length - i) % 3 == 0) {
        buffer.write('.');
      }
      buffer.write(s[i]);
    }
    final prefix = minor < 0 ? '-Rp ' : 'Rp ';
    return '$prefix${buffer.toString()}';
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.shiftService,
      builder: (context, _) {
        final shift = widget.shiftService.currentShift;
        final summary = widget.shiftService.currentSummary;
        final movements = widget.shiftService.movements;
        final isLoading = widget.shiftService.isLoading;
        final error = widget.shiftService.errorMessage;

        return Scaffold(
          backgroundColor: const Color(0xFF0F172A),
          appBar: AppBar(
            backgroundColor: const Color(0xFF1E293B),
            title: const Text(
              'Manajemen Shift & Kasir',
              style: TextStyle(color: Colors.white, fontWeight: FontWeight.bold),
            ),
            actions: [
              IconButton(
                key: const Key('shift_refresh_button'),
                icon: const Icon(Icons.refresh, color: Color(0xFF38BDF8)),
                onPressed: _loadShiftData,
                tooltip: 'Refresh',
              ),
            ],
          ),
          body: isLoading && shift == null
              ? const Center(child: CircularProgressIndicator(color: Color(0xFF38BDF8)))
              : SingleChildScrollView(
                  padding: const EdgeInsets.all(16),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      // Online-only policy banner
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
                        decoration: BoxDecoration(
                          color: const Color(0xFF0369A1).withValues(alpha: 0.2),
                          borderRadius: BorderRadius.circular(8),
                          border: Border.all(color: const Color(0xFF0284C7)),
                        ),
                        child: const Row(
                          children: [
                            Icon(Icons.wifi, color: Color(0xFF38BDF8), size: 18),
                            SizedBox(width: 10),
                            Expanded(
                              child: Text(
                                'Mutasi laci kasir wajib online untuk menjamin integritas saldo dan audit trail.',
                                style: TextStyle(color: Color(0xFFE0F2FE), fontSize: 12),
                              ),
                            ),
                          ],
                        ),
                      ),
                      const SizedBox(height: 16),

                      if (error != null) ...[
                        Container(
                          padding: const EdgeInsets.all(12),
                          decoration: BoxDecoration(
                            color: const Color(0xFF991B1B).withValues(alpha: 0.3),
                            borderRadius: BorderRadius.circular(8),
                            border: Border.all(color: const Color(0xFFEF4444)),
                          ),
                          child: Row(
                            children: [
                              const Icon(Icons.error_outline, color: Color(0xFFEF4444)),
                              const SizedBox(width: 8),
                              Expanded(
                                child: Text(
                                  error,
                                  style: const TextStyle(color: Color(0xFFFCA5A5), fontSize: 13),
                                ),
                              ),
                            ],
                          ),
                        ),
                        const SizedBox(height: 16),
                      ],

                      if (shift == null || !shift.isOpen)
                        _buildOpenShiftCard()
                      else ...[
                        _buildActiveShiftCard(shift),
                        const SizedBox(height: 16),
                        if (summary != null) _buildSummaryGrid(summary),
                        const SizedBox(height: 16),
                        _buildActionButtons(shift, summary),
                        const SizedBox(height: 24),
                        _buildMovementsSection(movements),
                      ],
                    ],
                  ),
                ),
        );
      },
    );
  }

  Widget _buildOpenShiftCard() {
    return Card(
      color: const Color(0xFF1E293B),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: Color(0xFF334155)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Row(
              children: [
                Icon(Icons.lock_clock, color: Color(0xFFF59E0B), size: 28),
                SizedBox(width: 12),
                Text(
                  'Laci Kasir Belum Dibuka',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 18,
                    fontWeight: FontWeight.bold,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 8),
            const Text(
              'Masukkan modal awal uang tunai (float) di laci sebelum memulai transaksi kasir.',
              style: TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
            ),
            const SizedBox(height: 20),
            TextField(
              key: const Key('shift_opening_cash_input'),
              controller: _openingCashController,
              keyboardType: TextInputType.number,
              style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
              decoration: InputDecoration(
                labelText: 'Modal Awal Tunai (Rp)',
                labelStyle: const TextStyle(color: Color(0xFF94A3B8)),
                prefixText: 'Rp ',
                prefixStyle: const TextStyle(color: Color(0xFF38BDF8), fontWeight: FontWeight.bold),
                filled: true,
                fillColor: const Color(0xFF0F172A),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(8),
                  borderSide: const BorderSide(color: Color(0xFF334155)),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(8),
                  borderSide: const BorderSide(color: Color(0xFF334155)),
                ),
              ),
            ),
            const SizedBox(height: 12),
            Wrap(
              spacing: 8,
              children: [50000, 100000, 200000, 500000].map((amt) {
                return ActionChip(
                  label: Text(_formatCurrency(amt)),
                  labelStyle: const TextStyle(color: Color(0xFF38BDF8), fontSize: 12),
                  backgroundColor: const Color(0xFF0F172A),
                  side: const BorderSide(color: Color(0xFF0284C7)),
                  onPressed: () {
                    setState(() {
                      _openingCashController.text = amt.toString();
                    });
                  },
                );
              }).toList(),
            ),
            const SizedBox(height: 16),
            TextField(
              key: const Key('shift_opening_notes_input'),
              controller: _openingNotesController,
              style: const TextStyle(color: Colors.white, fontSize: 14),
              decoration: InputDecoration(
                labelText: 'Catatan Pembukaan (Opsional)',
                labelStyle: const TextStyle(color: Color(0xFF94A3B8)),
                filled: true,
                fillColor: const Color(0xFF0F172A),
                border: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(8),
                  borderSide: const BorderSide(color: Color(0xFF334155)),
                ),
                enabledBorder: OutlineInputBorder(
                  borderRadius: BorderRadius.circular(8),
                  borderSide: const BorderSide(color: Color(0xFF334155)),
                ),
              ),
            ),
            const SizedBox(height: 24),
            ElevatedButton.icon(
              key: const Key('shift_open_button'),
              icon: const Icon(Icons.key, size: 20),
              label: const Text('Buka Shift Sekarang', style: TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
              style: ElevatedButton.styleFrom(
                backgroundColor: const Color(0xFF10B981),
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 16),
                shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
              ),
              onPressed: () async {
                final amt = int.tryParse(_openingCashController.text.trim()) ?? 0;
                final outlet = widget.tenantService.activeOutlet;
                if (outlet != null) {
                  final ok = await widget.shiftService.openShift(
                    outlet.id,
                    amt,
                    notes: _openingNotesController.text.trim(),
                  );
                  if (ok && mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text('Shift berhasil dibuka!'),
                        backgroundColor: Color(0xFF10B981),
                      ),
                    );
                  }
                }
              },
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildActiveShiftCard(ShiftModel shift) {
    return Card(
      color: const Color(0xFF1E293B),
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: const BorderSide(color: Color(0xFF10B981)),
      ),
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
                  decoration: BoxDecoration(
                    color: const Color(0xFF065F46),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: const Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Icon(Icons.circle, color: Color(0xFF34D399), size: 10),
                      SizedBox(width: 6),
                      Text(
                        'SHIFT AKTIF',
                        style: TextStyle(
                          color: Color(0xFFD1FAE5),
                          fontWeight: FontWeight.bold,
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                ),
                Text(
                  'ID: ${shift.id.substring(0, 8)}...',
                  style: const TextStyle(color: Color(0xFF64748B), fontSize: 11),
                ),
              ],
            ),
            const SizedBox(height: 12),
            Text(
              'Dibuka oleh: ${shift.openedByName ?? "Kasir"}',
              style: const TextStyle(color: Colors.white, fontWeight: FontWeight.w600, fontSize: 14),
            ),
            const SizedBox(height: 4),
            Text(
              'Waktu buka: ${shift.openedAt.toLocal().toString().substring(0, 19)}',
              style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 12),
            ),
            if (shift.notes != null && shift.notes!.isNotEmpty) ...[
              const SizedBox(height: 6),
              Text(
                'Catatan: ${shift.notes}',
                style: const TextStyle(color: Color(0xFFCBD5E1), fontSize: 12, fontStyle: FontStyle.italic),
              ),
            ],
          ],
        ),
      ),
    );
  }

  Widget _buildSummaryGrid(CashSummaryModel summary) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        // Big Expected Cash Hero Card
        Container(
          padding: const EdgeInsets.all(20),
          decoration: BoxDecoration(
            gradient: const LinearGradient(
              colors: [Color(0xFF065F46), Color(0xFF047857)],
              begin: Alignment.topLeft,
              end: Alignment.bottomRight,
            ),
            borderRadius: BorderRadius.circular(12),
            boxShadow: [
              BoxShadow(
                color: const Color(0xFF10B981).withValues(alpha: 0.2),
                blurRadius: 10,
                offset: const Offset(0, 4),
              ),
            ],
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text(
                'SALDO KAS DIHARAPKAN (SERVER AUTHORITY)',
                style: TextStyle(
                  color: Color(0xFFA7F3D0),
                  fontSize: 11,
                  fontWeight: FontWeight.bold,
                  letterSpacing: 0.5,
                ),
              ),
              const SizedBox(height: 8),
              Text(
                _formatCurrency(summary.expectedClosing),
                key: const Key('shift_expected_cash_text'),
                style: const TextStyle(
                  color: Colors.white,
                  fontSize: 32,
                  fontWeight: FontWeight.bold,
                ),
              ),
              const SizedBox(height: 4),
              const Text(
                'Total saldo fisik yang seharusnya ada di laci saat ini',
                style: TextStyle(color: Color(0xFFD1FAE5), fontSize: 12),
              ),
            ],
          ),
        ),
        const SizedBox(height: 12),

        // Breakdown items
        Container(
          padding: const EdgeInsets.all(16),
          decoration: BoxDecoration(
            color: const Color(0xFF1E293B),
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: const Color(0xFF334155)),
          ),
          child: Column(
            children: [
              _buildSummaryRow('Modal Awal (Opening)', summary.openingCash, const Color(0xFF38BDF8)),
              const Divider(color: Color(0xFF334155)),
              _buildSummaryRow('Penjualan Tunai (+)', summary.cashSales, const Color(0xFF34D399)),
              const Divider(color: Color(0xFF334155)),
              _buildSummaryRow('Kas Masuk (+)', summary.cashIn, const Color(0xFF34D399)),
              const Divider(color: Color(0xFF334155)),
              _buildSummaryRow('Kas Keluar (-)', summary.cashOut, const Color(0xFFF87171)),
              const Divider(color: Color(0xFF334155)),
              _buildSummaryRow('Refund Tunai (-)', summary.cashRefund, const Color(0xFFF87171)),
              const Divider(color: Color(0xFF334155)),
              _buildSummaryRow('Penyesuaian (+/-)', summary.adjustments, const Color(0xFFFBBF24)),
            ],
          ),
        ),
      ],
    );
  }

  Widget _buildSummaryRow(String label, int minor, Color valueColor) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Text(label, style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 13)),
          Text(
            _formatCurrency(minor),
            style: TextStyle(color: valueColor, fontWeight: FontWeight.bold, fontSize: 14),
          ),
        ],
      ),
    );
  }

  Widget _buildActionButtons(ShiftModel shift, CashSummaryModel? summary) {
    return Row(
      children: [
        Expanded(
          child: ElevatedButton.icon(
            key: const Key('shift_cash_in_button'),
            icon: const Icon(Icons.add_circle_outline, size: 18),
            label: const Text('Cash In'),
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF0F766E),
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () => _showCashInOutDialog(shift, isCashIn: true),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: ElevatedButton.icon(
            key: const Key('shift_cash_out_button'),
            icon: const Icon(Icons.remove_circle_outline, size: 18),
            label: const Text('Cash Out'),
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF9F1239),
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () => _showCashInOutDialog(shift, isCashIn: false),
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: ElevatedButton.icon(
            key: const Key('shift_close_button'),
            icon: const Icon(Icons.check_circle_outline, size: 18),
            label: const Text('Tutup Shift'),
            style: ElevatedButton.styleFrom(
              backgroundColor: const Color(0xFF4338CA),
              foregroundColor: Colors.white,
              padding: const EdgeInsets.symmetric(vertical: 14),
              shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
            ),
            onPressed: () => _showCloseShiftDialog(shift, summary?.expectedClosing ?? 0),
          ),
        ),
      ],
    );
  }

  void _showCashInOutDialog(ShiftModel shift, {required bool isCashIn}) {
    final amtController = TextEditingController();
    final reasonController = TextEditingController();

    showDialog(
      context: context,
      builder: (dialogCtx) => AlertDialog(
        backgroundColor: const Color(0xFF1E293B),
        title: Text(
          isCashIn ? 'Catat Uang Masuk (Cash In)' : 'Catat Uang Keluar (Cash Out)',
          style: const TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
        ),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: amtController,
              keyboardType: TextInputType.number,
              style: const TextStyle(color: Colors.white, fontSize: 16),
              decoration: InputDecoration(
                labelText: 'Jumlah Nominal (Rp)',
                labelStyle: const TextStyle(color: Color(0xFF94A3B8)),
                prefixText: 'Rp ',
                filled: true,
                fillColor: const Color(0xFF0F172A),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
            const SizedBox(height: 12),
            TextField(
              controller: reasonController,
              style: const TextStyle(color: Colors.white, fontSize: 14),
              decoration: InputDecoration(
                labelText: isCashIn ? 'Alasan (misal: tambah receh)' : 'Alasan (misal: beli plastik)',
                labelStyle: const TextStyle(color: Color(0xFF94A3B8)),
                filled: true,
                fillColor: const Color(0xFF0F172A),
                border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            child: const Text('Batal', style: TextStyle(color: Color(0xFF94A3B8))),
            onPressed: () => Navigator.of(dialogCtx).pop(),
          ),
          ElevatedButton(
            style: ElevatedButton.styleFrom(
              backgroundColor: isCashIn ? const Color(0xFF10B981) : const Color(0xFFEF4444),
              foregroundColor: Colors.white,
            ),
            child: const Text('Simpan'),
            onPressed: () async {
              final amt = int.tryParse(amtController.text.trim()) ?? 0;
              final reason = reasonController.text.trim();
              if (amt <= 0 || reason.isEmpty) return;

              Navigator.of(dialogCtx).pop();

              final ok = isCashIn
                  ? await widget.shiftService.cashIn(shift.id, amt, reason)
                  : await widget.shiftService.cashOut(shift.id, amt, reason);

              if (ok && mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  SnackBar(
                    content: Text(isCashIn ? 'Cash in berhasil disimpan' : 'Cash out berhasil disimpan'),
                    backgroundColor: const Color(0xFF10B981),
                  ),
                );
              }
            },
          ),
        ],
      ),
    );
  }

  void _showCloseShiftDialog(ShiftModel shift, int expectedCash) {
    final actualController = TextEditingController(text: expectedCash.toString());
    final notesController = TextEditingController();

    showDialog(
      context: context,
      builder: (dialogCtx) => StatefulBuilder(
        builder: (ctx, setDialogState) {
          final actual = int.tryParse(actualController.text.trim()) ?? 0;
          final variance = actual - expectedCash;

          Color varColor = const Color(0xFF94A3B8);
          String varPrefix = 'Pas';
          if (variance > 0) {
            varColor = const Color(0xFF34D399);
            varPrefix = 'Lebih';
          } else if (variance < 0) {
            varColor = const Color(0xFFF87171);
            varPrefix = 'Kurang';
          }

          return AlertDialog(
            backgroundColor: const Color(0xFF1E293B),
            title: const Text(
              'Tutup Shift & Hitung Uang Fisik',
              style: TextStyle(color: Colors.white, fontSize: 16, fontWeight: FontWeight.bold),
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(
                    'Saldo Sistem: ${_formatCurrency(expectedCash)}',
                    style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 13),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    key: const Key('shift_close_actual_cash_input'),
                    controller: actualController,
                    keyboardType: TextInputType.number,
                    style: const TextStyle(color: Colors.white, fontSize: 18, fontWeight: FontWeight.bold),
                    decoration: InputDecoration(
                      labelText: 'Uang Fisik Dihitung (Rp)',
                      labelStyle: const TextStyle(color: Color(0xFF94A3B8)),
                      prefixText: 'Rp ',
                      filled: true,
                      fillColor: const Color(0xFF0F172A),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                    onChanged: (_) => setDialogState(() {}),
                  ),
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: const Color(0xFF0F172A),
                      borderRadius: BorderRadius.circular(8),
                      border: Border.all(color: varColor),
                    ),
                    child: Row(
                      mainAxisAlignment: MainAxisAlignment.spaceBetween,
                      children: [
                        Text('Selisih ($varPrefix):', style: const TextStyle(color: Colors.white, fontSize: 13)),
                        Text(
                          _formatCurrency(variance),
                          style: TextStyle(color: varColor, fontWeight: FontWeight.bold, fontSize: 15),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    key: const Key('shift_close_notes_input'),
                    controller: notesController,
                    style: const TextStyle(color: Colors.white, fontSize: 14),
                    decoration: InputDecoration(
                      labelText: 'Catatan Penutupan',
                      labelStyle: const TextStyle(color: Color(0xFF94A3B8)),
                      filled: true,
                      fillColor: const Color(0xFF0F172A),
                      border: OutlineInputBorder(borderRadius: BorderRadius.circular(8)),
                    ),
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(
                child: const Text('Batal', style: TextStyle(color: Color(0xFF94A3B8))),
                onPressed: () => Navigator.of(dialogCtx).pop(),
              ),
              ElevatedButton(
                key: const Key('shift_confirm_close_button'),
                style: ElevatedButton.styleFrom(
                  backgroundColor: const Color(0xFF6366F1),
                  foregroundColor: Colors.white,
                ),
                child: const Text('Konfirmasi Tutup Shift'),
                onPressed: () async {
                  final actualAmt = int.tryParse(actualController.text.trim()) ?? 0;
                  Navigator.of(dialogCtx).pop();

                  final ok = await widget.shiftService.closeShift(
                    shift.id,
                    actualAmt,
                    notes: notesController.text.trim(),
                  );

                  if (ok && mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(
                        content: Text('Shift berhasil ditutup!'),
                        backgroundColor: Color(0xFF10B981),
                      ),
                    );
                  }
                },
              ),
            ],
          );
        },
      ),
    );
  }

  Widget _buildMovementsSection(List<CashMovementModel> movements) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text(
          'RIWAYAT MUTASI KASIR',
          style: TextStyle(
            color: Color(0xFF94A3B8),
            fontSize: 13,
            fontWeight: FontWeight.bold,
            letterSpacing: 0.5,
          ),
        ),
        const SizedBox(height: 8),
        if (movements.isEmpty)
          Container(
            padding: const EdgeInsets.all(20),
            decoration: BoxDecoration(
              color: const Color(0xFF1E293B),
              borderRadius: BorderRadius.circular(8),
            ),
            child: const Center(
              child: Text(
                'Belum ada mutasi kas pada shift ini.',
                style: TextStyle(color: Color(0xFF64748B), fontSize: 13),
              ),
            ),
          )
        else
          ListView.separated(
            shrinkWrap: true,
            physics: const NeverScrollableScrollPhysics(),
            itemCount: movements.length,
            separatorBuilder: (context, index) => const SizedBox(height: 8),
            itemBuilder: (ctx, i) {
              final m = movements[i];
              final isIn = m.isIn;
              return Container(
                padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                decoration: BoxDecoration(
                  color: const Color(0xFF1E293B),
                  borderRadius: BorderRadius.circular(8),
                  border: Border.all(color: const Color(0xFF334155)),
                ),
                child: Row(
                  children: [
                    CircleAvatar(
                      radius: 16,
                      backgroundColor: isIn ? const Color(0xFF065F46) : const Color(0xFF881337),
                      child: Icon(
                        isIn ? Icons.arrow_downward : Icons.arrow_upward,
                        color: isIn ? const Color(0xFF34D399) : const Color(0xFFFB7185),
                        size: 16,
                      ),
                    ),
                    const SizedBox(width: 12),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            m.reason ?? m.type.toUpperCase(),
                            style: const TextStyle(color: Colors.white, fontSize: 13, fontWeight: FontWeight.w600),
                          ),
                          const SizedBox(height: 2),
                          Text(
                            'Tipe: ${m.type} • Oleh: ${m.performedByName ?? "Kasir"}',
                            style: const TextStyle(color: Color(0xFF94A3B8), fontSize: 11),
                          ),
                        ],
                      ),
                    ),
                    Text(
                      '${isIn ? "+" : "-"}${_formatCurrency(m.amountMinor)}',
                      style: TextStyle(
                        color: isIn ? const Color(0xFF34D399) : const Color(0xFFFB7185),
                        fontWeight: FontWeight.bold,
                        fontSize: 14,
                      ),
                    ),
                  ],
                ),
              );
            },
          ),
      ],
    );
  }
}
