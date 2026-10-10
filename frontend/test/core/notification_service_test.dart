import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/notification/notification_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('fetchNotifications and markAsRead work properly', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/notifications' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'notif-1',
                'tenant_id': 'tenant-1',
                'recipient_user_id': null,
                'type': 'low_stock',
                'title': 'Stok Menipis',
                'message': 'Biji Kopi Robusta sisa 3',
                'severity': 'warning',
                'data': {'item_id': 'item-1'},
                'read_at': null,
                'created_at': '2026-10-06T10:00:00Z',
              },
            ],
            'meta': {'current_page': 1, 'per_page': 20, 'total': 1},
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/notifications/unread-count' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {'unread_count': 1},
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/notifications/notif-1/read' && request.method == 'POST') {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'notif-1',
              'tenant_id': 'tenant-1',
              'type': 'low_stock',
              'title': 'Stok Menipis',
              'message': 'Biji Kopi Robusta sisa 3',
              'severity': 'warning',
              'read_at': '2026-10-06T10:05:00Z',
              'created_at': '2026-10-06T10:00:00Z',
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = NotificationService(apiClient: apiClient);

    await service.fetchNotifications();
    expect(service.notifications.length, equals(1));
    expect(service.notifications.first.title, equals('Stok Menipis'));
    expect(service.notifications.first.isRead, isFalse);

    await service.fetchUnreadCount();
    expect(service.unreadCount, equals(1));

    final success = await service.markAsRead('notif-1');
    expect(success, isTrue);
    expect(service.notifications.first.isRead, isTrue);
    expect(service.unreadCount, equals(0));
  });

  test('markAllAsRead marks all notifications as read', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/notifications' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'notif-1',
                'tenant_id': 'tenant-1',
                'type': 'low_stock',
                'title': 'Stok Menipis',
                'message': 'Biji Kopi Robusta sisa 3',
                'severity': 'warning',
                'read_at': null,
                'created_at': '2026-10-06T10:00:00Z',
              },
            ],
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/notifications/read-all' && request.method == 'POST') {
        return http.Response(
          jsonEncode({
            'message': 'Semua notifikasi telah ditandai dibaca.',
            'data': {'marked_read_count': 1},
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = NotificationService(apiClient: apiClient);

    await service.fetchNotifications();
    expect(service.unreadCount, equals(1));

    final success = await service.markAllAsRead();
    expect(success, isTrue);
    expect(service.unreadCount, equals(0));
    expect(service.notifications.first.isRead, isTrue);
  });
}
