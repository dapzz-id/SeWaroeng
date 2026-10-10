import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/notification/notification_service.dart';
import 'package:frontend/presentation/notification/notification_list_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('renders NotificationListScreen and marks notification as read', (
    tester,
  ) async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/notifications' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'notif-100',
                'tenant_id': 'tenant-1',
                'type': 'low_stock',
                'title': 'Stok Menipis: Kopi Arabika',
                'message': 'Sisa stok 2 kg, di bawah batas reorder 10 kg.',
                'severity': 'warning',
                'data': {'item_id': 'item-100'},
                'read_at': null,
                'created_at': '2026-10-06T12:00:00Z',
              },
            ],
            'meta': {'current_page': 1, 'per_page': 20, 'total': 1},
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/notifications/unread-count') {
        return http.Response(
          jsonEncode({
            'data': {'unread_count': 1},
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/notifications/notif-100/read' && request.method == 'POST') {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'notif-100',
              'tenant_id': 'tenant-1',
              'type': 'low_stock',
              'title': 'Stok Menipis: Kopi Arabika',
              'message': 'Sisa stok 2 kg, di bawah batas reorder 10 kg.',
              'severity': 'warning',
              'read_at': '2026-10-06T12:05:00Z',
              'created_at': '2026-10-06T12:00:00Z',
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final notificationService = NotificationService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: NotificationListScreen(
          notificationService: notificationService,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Notifikasi & Peringatan'), findsOneWidget);
    expect(find.text('Stok Menipis: Kopi Arabika'), findsOneWidget);
    expect(find.text('Sisa stok 2 kg, di bawah batas reorder 10 kg.'), findsOneWidget);

    // Tap on the notification item
    await tester.tap(find.byKey(const Key('notification_item_notif-100')));
    await tester.pumpAndSettle();

    expect(notificationService.notifications.first.isRead, isTrue);
  });
}
