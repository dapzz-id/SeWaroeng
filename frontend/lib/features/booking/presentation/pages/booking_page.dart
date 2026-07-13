// Neura Cakrawira Solusi | SeWaroeng
// Booking Form (Buyer UI)

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/network/api_client.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../shared/widgets/glass_container.dart';
import 'my_bookings_page.dart';

class BookingPage extends ConsumerStatefulWidget {
  final String storeId;
  final String storeName;

  const BookingPage({super.key, required this.storeId, required this.storeName});

  @override
  ConsumerState<BookingPage> createState() => _BookingPageState();
}

class _BookingPageState extends ConsumerState<BookingPage> {
  bool _isSubmitting = false;
  DateTime? _selectedDate;
  TimeOfDay? _selectedTime;
  int _guestCount = 1;
  final TextEditingController _notesController = TextEditingController();

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: Text('Booking: ${widget.storeName}'),
        backgroundColor: AppColors.surface,
        actions: [
          TextButton.icon(
            onPressed: () => Navigator.push(
              context,
              MaterialPageRoute(builder: (_) => const MyBookingsPage()),
            ),
            icon: const Icon(Icons.list_alt),
            label: const Text('Status Booking'),
          ),
        ],
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.all(16.0),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            GlassContainer(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text('Tanggal Reservasi', style: TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  ListTile(
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    tileColor: AppColors.surfaceVariant,
                    leading: const Icon(Icons.calendar_today),
                    title: Text(_selectedDate == null ? 'Pilih Tanggal' : '${_selectedDate!.day}/${_selectedDate!.month}/${_selectedDate!.year}'),
                    onTap: () async {
                      final date = await showDatePicker(
                        context: context,
                        initialDate: DateTime.now(),
                        firstDate: DateTime.now(),
                        lastDate: DateTime.now().add(const Duration(days: 30)),
                      );
                      if (date != null) setState(() => _selectedDate = date);
                    },
                  ),
                  const SizedBox(height: 16),
                  const Text('Waktu', style: TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  ListTile(
                    shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(8)),
                    tileColor: AppColors.surfaceVariant,
                    leading: const Icon(Icons.access_time),
                    title: Text(_selectedTime == null ? 'Pilih Waktu' : _selectedTime!.format(context)),
                    onTap: () async {
                      final time = await showTimePicker(
                        context: context,
                        initialTime: TimeOfDay.now(),
                      );
                      if (time != null) setState(() => _selectedTime = time);
                    },
                  ),
                  const SizedBox(height: 16),
                  const Text('Jumlah Orang', style: TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  Row(
                    children: [
                      IconButton(
                        icon: const Icon(Icons.remove_circle_outline),
                        onPressed: _guestCount > 1 ? () => setState(() => _guestCount--) : null,
                      ),
                      Text('$_guestCount Orang', style: const TextStyle(fontSize: 16)),
                      IconButton(
                        icon: const Icon(Icons.add_circle_outline),
                        onPressed: () => setState(() => _guestCount++),
                      ),
                    ],
                  ),
                  const SizedBox(height: 16),
                  const Text('Catatan Tambahan', style: TextStyle(fontWeight: FontWeight.bold)),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _notesController,
                    maxLines: 3,
                    decoration: const InputDecoration(
                      hintText: 'Misal: Dekat jendela, ada kursi bayi, dsb.',
                      filled: true,
                      fillColor: AppColors.surfaceVariant,
                    ),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 24),
            ElevatedButton(
              onPressed: (_selectedDate != null && _selectedTime != null && !_isSubmitting) ? () async {
                setState(() => _isSubmitting = true);
                try {
                  final api = ref.read(apiClientProvider);
                  final scheduledAt = DateTime(
                    _selectedDate!.year,
                    _selectedDate!.month,
                    _selectedDate!.day,
                    _selectedTime!.hour,
                    _selectedTime!.minute,
                  );
                  await api.post('/bookings', data: {
                    'store_id': widget.storeId,
                    'scheduled_at': scheduledAt.toIso8601String(),
                    'guest_count': _guestCount,
                    'notes': _notesController.text.isEmpty ? null : _notesController.text,
                  });
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      const SnackBar(content: Text('✅ Permintaan Booking Dikirim! Menunggu persetujuan seller.')),
                    );
                    Navigator.pop(context);
                  }
                } catch (e) {
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(content: Text('❌ Gagal booking: $e')),
                    );
                  }
                } finally {
                  if (mounted) setState(() => _isSubmitting = false);
                }
              } : null,
              child: _isSubmitting
                  ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Text('AJUKAN BOOKING'),
            ),
          ],
        ),
      ),
    );
  }
}
