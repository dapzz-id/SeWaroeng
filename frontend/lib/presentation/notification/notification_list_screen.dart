import 'package:flutter/material.dart';
import 'package:frontend/core/inventory/inventory_service.dart';
import 'package:frontend/core/notification/notification_models.dart';
import 'package:frontend/core/notification/notification_service.dart';
import 'package:frontend/core/shift/shift_service.dart';
import 'package:frontend/core/tenant/tenant_service.dart';
import 'package:frontend/presentation/inventory/inventory_dashboard_screen.dart';
import 'package:frontend/presentation/shift/shift_management_screen.dart';

class NotificationListScreen extends StatefulWidget {
  final NotificationService notificationService;
  final InventoryService? inventoryService;
  final ShiftService? shiftService;
  final TenantService? tenantService;

  const NotificationListScreen({
    super.key,
    required this.notificationService,
    this.inventoryService,
    this.shiftService,
    this.tenantService,
  });

  @override
  State<NotificationListScreen> createState() => _NotificationListScreenState();
}

class _NotificationListScreenState extends State<NotificationListScreen> {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      widget.notificationService.fetchNotifications();
    });
  }

  Color _severityColor(String severity) {
    switch (severity) {
      case 'critical':
        return const Color(0xFFEF4444);
      case 'warning':
        return const Color(0xFFF59E0B);
      case 'info':
      default:
        return const Color(0xFF38BDF8);
    }
  }

  IconData _notificationIcon(String type) {
    switch (type) {
      case 'low_stock':
        return Icons.inventory_2_outlined;
      case 'shift_variance':
        return Icons.point_of_sale_outlined;
      case 'payment_failed':
      case 'payment_status':
        return Icons.payment_outlined;
      default:
        return Icons.notifications_outlined;
    }
  }

  void _handleNotificationTap(AppNotification notif) {
    if (!notif.isRead) {
      widget.notificationService.markAsRead(notif.id);
    }

    if (notif.type == 'low_stock' && widget.inventoryService != null) {
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => InventoryDashboardScreen(
            inventoryService: widget.inventoryService!,
          ),
        ),
      );
    } else if (notif.type == 'shift_variance' &&
        widget.shiftService != null &&
        widget.tenantService != null) {
      Navigator.of(context).push(
        MaterialPageRoute(
          builder: (_) => ShiftManagementScreen(
            shiftService: widget.shiftService!,
            tenantService: widget.tenantService!,
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: widget.notificationService,
      builder: (context, _) {
        final notifications = widget.notificationService.notifications;
        final isLoading = widget.notificationService.isLoading;
        final errorMessage = widget.notificationService.errorMessage;

        return Scaffold(
          backgroundColor: const Color(0xFF0F172A),
          appBar: AppBar(
            title: const Text(
              'Notifikasi & Peringatan',
              style: TextStyle(color: Colors.white, fontSize: 18),
            ),
            backgroundColor: const Color(0xFF1E293B),
            elevation: 0,
            actions: [
              if (notifications.any((n) => !n.isRead))
                TextButton.icon(
                  key: const Key('notification_mark_all_read_button'),
                  icon: const Icon(Icons.done_all, size: 18, color: Color(0xFF38BDF8)),
                  label: const Text(
                    'Tandai Semua Dibaca',
                    style: TextStyle(color: Color(0xFF38BDF8), fontSize: 13),
                  ),
                  onPressed: () {
                    widget.notificationService.markAllAsRead();
                  },
                ),
            ],
          ),
          body: isLoading && notifications.isEmpty
              ? const Center(
                  child: CircularProgressIndicator(color: Color(0xFF38BDF8)),
                )
              : errorMessage != null && notifications.isEmpty
                  ? Center(
                      child: Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          const Icon(Icons.error_outline, color: Color(0xFFEF4444), size: 48),
                          const SizedBox(height: 12),
                          Text(
                            errorMessage,
                            style: const TextStyle(color: Colors.white70),
                            textAlign: TextAlign.center,
                          ),
                          const SizedBox(height: 16),
                          ElevatedButton(
                            onPressed: () => widget.notificationService.fetchNotifications(),
                            child: const Text('Coba Lagi'),
                          ),
                        ],
                      ),
                    )
                  : notifications.isEmpty
                      ? const Center(
                          child: Column(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(Icons.notifications_none, color: Color(0xFF64748B), size: 56),
                              SizedBox(height: 12),
                              Text(
                                'Belum ada notifikasi baru',
                                style: TextStyle(color: Color(0xFF94A3B8), fontSize: 16),
                              ),
                            ],
                          ),
                        )
                      : RefreshIndicator(
                          onRefresh: () => widget.notificationService.fetchNotifications(),
                          child: ListView.separated(
                            padding: const EdgeInsets.all(16),
                            itemCount: notifications.length,
                            separatorBuilder: (_, _) => const SizedBox(height: 10),
                            itemBuilder: (context, index) {
                              final notif = notifications[index];
                              final color = _severityColor(notif.severity);
                              final icon = _notificationIcon(notif.type);

                              return InkWell(
                                key: Key('notification_item_${notif.id}'),
                                onTap: () => _handleNotificationTap(notif),
                                borderRadius: BorderRadius.circular(12),
                                child: Container(
                                  padding: const EdgeInsets.all(14),
                                  decoration: BoxDecoration(
                                    color: notif.isRead
                                        ? const Color(0xFF1E293B).withAlpha(160)
                                        : const Color(0xFF1E293B),
                                    borderRadius: BorderRadius.circular(12),
                                    border: Border.all(
                                      color: notif.isRead
                                          ? const Color(0xFF334155)
                                          : color.withAlpha(120),
                                      width: notif.isRead ? 1 : 1.5,
                                    ),
                                  ),
                                  child: Row(
                                    crossAxisAlignment: CrossAxisAlignment.start,
                                    children: [
                                      Container(
                                        padding: const EdgeInsets.all(10),
                                        decoration: BoxDecoration(
                                          color: color.withAlpha(35),
                                          shape: BoxShape.circle,
                                        ),
                                        child: Icon(icon, color: color, size: 22),
                                      ),
                                      const SizedBox(width: 14),
                                      Expanded(
                                        child: Column(
                                          crossAxisAlignment: CrossAxisAlignment.start,
                                          children: [
                                            Row(
                                              children: [
                                                Expanded(
                                                  child: Text(
                                                    notif.title,
                                                    style: TextStyle(
                                                      color: Colors.white,
                                                      fontWeight: notif.isRead
                                                          ? FontWeight.w500
                                                          : FontWeight.bold,
                                                      fontSize: 15,
                                                    ),
                                                  ),
                                                ),
                                                if (!notif.isRead)
                                                  Container(
                                                    width: 8,
                                                    height: 8,
                                                    decoration: BoxDecoration(
                                                      color: color,
                                                      shape: BoxShape.circle,
                                                    ),
                                                  ),
                                              ],
                                            ),
                                            const SizedBox(height: 4),
                                            Text(
                                              notif.message,
                                              style: TextStyle(
                                                color: notif.isRead
                                                    ? const Color(0xFF94A3B8)
                                                    : const Color(0xFFCBD5E1),
                                                fontSize: 13,
                                              ),
                                            ),
                                            const SizedBox(height: 8),
                                            Row(
                                              children: [
                                                Container(
                                                  padding: const EdgeInsets.symmetric(
                                                    horizontal: 8,
                                                    vertical: 2,
                                                  ),
                                                  decoration: BoxDecoration(
                                                    color: color.withAlpha(30),
                                                    borderRadius: BorderRadius.circular(6),
                                                  ),
                                                  child: Text(
                                                    notif.severity.toUpperCase(),
                                                    style: TextStyle(
                                                      color: color,
                                                      fontSize: 10,
                                                      fontWeight: FontWeight.bold,
                                                    ),
                                                  ),
                                                ),
                                                const Spacer(),
                                                if (notif.createdAt != null)
                                                  Text(
                                                    '${notif.createdAt!.day.toString().padLeft(2, '0')}/${notif.createdAt!.month.toString().padLeft(2, '0')} ${notif.createdAt!.hour.toString().padLeft(2, '0')}:${notif.createdAt!.minute.toString().padLeft(2, '0')}',
                                                    style: const TextStyle(
                                                      color: Color(0xFF64748B),
                                                      fontSize: 11,
                                                    ),
                                                  ),
                                              ],
                                            ),
                                          ],
                                        ),
                                      ),
                                    ],
                                  ),
                                ),
                              );
                            },
                          ),
                        ),
        );
      },
    );
  }
}
