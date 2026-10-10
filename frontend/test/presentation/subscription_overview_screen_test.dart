import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/subscription/subscription_service.dart';
import 'package:frontend/presentation/subscription/subscription_overview_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('SubscriptionOverviewScreen renders plan, quotas, and entitlements', (tester) async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/subscription' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'sub-test-1',
              'tenant_id': 'tenant-test-1',
              'status': 'trialing',
              'is_operational': true,
              'is_trial': true,
              'trial_remaining_days': 10,
              'trial_ends_at': '2026-10-16T12:00:00Z',
              'starts_at': '2026-10-06T12:00:00Z',
              'current_period_start': '2026-10-06T12:00:00Z',
              'current_period_end': '2026-10-16T12:00:00Z',
              'plan': {
                'id': 'plan-1',
                'code': 'free',
                'name': 'Free / Trial',
                'description': 'Paket evaluasi SeWaroeng',
                'price_minor': 0,
                'currency': 'IDR',
                'billing_interval': 'monthly',
              },
              'add_ons': [],
            }
          }),
          200,
        );
      }

      if (request.url.path == '/api/v1/subscription/usage' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'max_outlets': {'limit': 5, 'current': 2, 'remaining': 3, 'is_unlimited': false},
              'max_users': {'limit': 5, 'current': 3, 'remaining': 2, 'is_unlimited': false},
              'max_products': {'limit': 100, 'current': 25, 'remaining': 75, 'is_unlimited': false},
              'max_monthly_transactions': {'limit': 500, 'current': 120, 'remaining': 380, 'is_unlimited': false},
            }
          }),
          200,
        );
      }

      if (request.url.path == '/api/v1/subscription/entitlements' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'features': {
                'pos': true,
                'inventory': true,
                'customer_crm': true,
                'finance': true,
                'dashboard': true,
                'offline_pos': true,
                'multi_outlet': true,
                'advanced_reports': false,
                'whatsapp': false,
                'ai_copilot': false,
              }
            }
          }),
          200,
        );
      }

      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost', httpClient: mockClient);
    final service = SubscriptionService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: SubscriptionOverviewScreen(subscriptionService: service),
      ),
    );

    // Initial pump and wait for API calls to settle
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 200));

    // Verify Title
    expect(find.text('Langganan & Kuota'), findsOneWidget);

    // Verify Plan Card
    expect(find.text('Free / Trial'), findsOneWidget);
    expect(find.text('Masa Uji Coba (Trial)'), findsOneWidget);
    expect(find.text('Trial aktif: 10 hari tersisa'), findsOneWidget);

    // Verify Usage Section
    expect(find.text('Penggunaan Kuota'), findsOneWidget);
    expect(find.text('2 / 5'), findsOneWidget); // Outlets: 2 / 5
    expect(find.text('3 / 5'), findsOneWidget); // Users: 3 / 5

    // Scroll down to see cancel button at bottom of list
    await tester.scrollUntilVisible(
      find.text('Batalkan Langganan'),
      300.0,
      scrollable: find.byType(Scrollable).first,
    );
    await tester.pumpAndSettle();

    // Verify Cancel button
    expect(find.text('Batalkan Langganan'), findsOneWidget);
  });
}
