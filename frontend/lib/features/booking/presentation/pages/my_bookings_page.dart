// Neura Cakrawira Solusi | SeWaroeng
// Booking Status Tracker — shows pending/approved/rejected state with seller notification

import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/network/api_client.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../shared/widgets/glass_container.dart';
import '../../../../shared/widgets/glass_skeleton_loader.dart';

// ─── Booking model ─────────────────────────────────────────────────────────

class BookingStatusModel {
  final String id;
  final String storeId;
  final String storeName;
  final DateTime scheduledAt;
  final int guestCount;
  final String status; // pending, approved, rejected
  final String? notes;
  final DateTime createdAt;

  const BookingStatusModel({
    required this.id,
    required this.storeId,
    required this.storeName,
    required this.scheduledAt,
    required this.guestCount,
    required this.status,
    this.notes,
    required this.createdAt,
  });

  factory BookingStatusModel.fromJson(Map<String, dynamic> json) {
    return BookingStatusModel(
      id: json['id'] as String,
      storeId: json['store_id'] as String,
      storeName: (json['store'] as Map<String, dynamic>?)?['name'] as String? ?? '—',
      scheduledAt: DateTime.parse(json['scheduled_at'] as String),
      guestCount: json['guest_count'] as int,
      status: json['status'] as String,
      notes: json['notes'] as String?,
      createdAt: DateTime.parse(json['created_at'] as String),
    );
  }
}

// ─── Provider ─────────────────────────────────────────────────────────────

final myBookingsProvider = FutureProvider<List<BookingStatusModel>>((ref) async {
  final api = ref.watch(apiClientProvider);
  final response = await api.get<Map<String, dynamic>>('/bookings/my');
  final data = response.data?['data'] as List<dynamic>? ?? [];
  return data
      .map((e) => BookingStatusModel.fromJson(e as Map<String, dynamic>))
      .toList();
});

// ─── Page ─────────────────────────────────────────────────────────────────

class MyBookingsPage extends ConsumerWidget {
  const MyBookingsPage({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final bookingsAsync = ref.watch(myBookingsProvider);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Booking Saya'),
        backgroundColor: AppColors.surface,
      ),
      body: bookingsAsync.when(
        loading: () => ListView.builder(
          padding: const EdgeInsets.all(16),
          itemCount: 3,
          itemBuilder: (_, __) => Padding(
            padding: const EdgeInsets.only(bottom: 12),
            child: GlassSkeletonLoader(height: 100, borderRadius: BorderRadius.circular(12)),
          ),
        ),
        error: (e, _) => Center(child: Text('Error: $e')),
        data: (bookings) {
          if (bookings.isEmpty) {
            return const Center(child: Text('Belum ada booking.'));
          }
          return ListView.separated(
            padding: const EdgeInsets.all(16),
            itemCount: bookings.length,
            separatorBuilder: (_, __) => const SizedBox(height: 12),
            itemBuilder: (context, index) {
              final b = bookings[index];
              return _BookingCard(booking: b);
            },
          );
        },
      ),
    );
  }
}

class _BookingCard extends StatelessWidget {
  final BookingStatusModel booking;
  const _BookingCard({required this.booking});

  @override
  Widget build(BuildContext context) {
    final statusInfo = _statusInfo(booking.status);

    return GlassContainer(
      padding: const EdgeInsets.all(16),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          // Status icon
          Container(
            width: 48,
            height: 48,
            decoration: BoxDecoration(
              color: statusInfo.$2.withValues(alpha: 0.15),
              shape: BoxShape.circle,
            ),
            child: Icon(statusInfo.$1, color: statusInfo.$2),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(booking.storeName, style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 16)),
                const SizedBox(height: 4),
                Text(
                  '${_fmt(booking.scheduledAt)} · ${booking.guestCount} orang',
                  style: const TextStyle(color: AppColors.grey400),
                ),
                if (booking.notes != null) ...[
                  const SizedBox(height: 4),
                  Text(booking.notes!, style: const TextStyle(color: AppColors.grey400, fontSize: 13)),
                ],
              ],
            ),
          ),
          // Status badge
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
            decoration: BoxDecoration(
              color: statusInfo.$2.withValues(alpha: 0.15),
              borderRadius: BorderRadius.circular(20),
            ),
            child: Text(
              statusInfo.$3,
              style: TextStyle(color: statusInfo.$2, fontWeight: FontWeight.w600, fontSize: 12),
            ),
          ),
        ],
      ),
    );
  }

  String _fmt(DateTime dt) =>
      '${dt.day}/${dt.month}/${dt.year} ${dt.hour.toString().padLeft(2, '0')}:${dt.minute.toString().padLeft(2, '0')}';

  (IconData, Color, String) _statusInfo(String status) {
    return switch (status) {
      'approved' => (Icons.check_circle, Colors.greenAccent, 'Disetujui'),
      'rejected' => (Icons.cancel, Colors.redAccent, 'Ditolak'),
      _ => (Icons.hourglass_top, Colors.orangeAccent, 'Menunggu'),
    };
  }
}
