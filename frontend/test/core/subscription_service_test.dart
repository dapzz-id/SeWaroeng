import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/subscription/subscription_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('SubscriptionService Unit Tests', () {
    test('loadSubscriptionData fetches subscription, usage, and entitlements successfully', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/subscription' && request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'data': {
                'id': 'sub-1',
                'tenant_id': 'tenant-1',
                'status': 'trialing',
                'is_operational': true,
                'is_trial': true,
                'trial_remaining_days': 12,
                'trial_ends_at': '2026-10-18T12:00:00Z',
                'starts_at': '2026-10-06T12:00:00Z',
                'current_period_start': '2026-10-06T12:00:00Z',
                'current_period_end': '2026-10-18T12:00:00Z',
                'plan': {
                  'id': 'plan-1',
                  'code': 'free',
                  'name': 'Free / Trial',
                  'description': 'Paket dasar',
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
                'max_outlets': {'limit': 5, 'current': 1, 'remaining': 4, 'is_unlimited': false},
                'max_users': {'limit': 5, 'current': 2, 'remaining': 3, 'is_unlimited': false},
                'max_products': {'limit': 100, 'current': 15, 'remaining': 85, 'is_unlimited': false},
                'max_monthly_transactions': {'limit': 500, 'current': 42, 'remaining': 458, 'is_unlimited': false},
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
                  'offline_pos': true,
                  'multi_outlet': true,
                  'whatsapp': false,
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

      await service.loadSubscriptionData();

      expect(service.subscription, isNotNull);
      expect(service.subscription!.plan.code, equals('free'));
      expect(service.subscription!.isTrial, isTrue);
      expect(service.subscription!.trialRemainingDays, equals(12));

      expect(service.usage, isNotNull);
      expect(service.usage!.maxOutlets.current, equals(1));
      expect(service.usage!.maxOutlets.limit, equals(5));

      expect(service.entitlements, isNotNull);
      expect(service.entitlements!.can('pos'), isTrue);
      expect(service.entitlements!.can('whatsapp'), isFalse);
    });

    test('cancelSubscription calls API and updates state', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/subscription/cancel' && request.method == 'POST') {
          return http.Response(
            jsonEncode({
              'data': {
                'id': 'sub-1',
                'status': 'cancelled',
                'cancelled_at': '2026-10-06T14:00:00Z',
              },
              'message': 'Subscription cancelled',
            }),
            200,
          );
        }
        if (request.url.path == '/api/v1/subscription' && request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'data': {
                'id': 'sub-1',
                'tenant_id': 'tenant-1',
                'status': 'cancelled',
                'is_operational': true,
                'is_trial': false,
                'trial_remaining_days': 0,
                'starts_at': '2026-10-06T12:00:00Z',
                'current_period_start': '2026-10-06T12:00:00Z',
                'current_period_end': '2026-11-06T12:00:00Z',
                'cancelled_at': '2026-10-06T14:00:00Z',
                'plan': {
                  'id': 'plan-1',
                  'code': 'starter',
                  'name': 'Starter',
                  'price_minor': 9900000,
                  'currency': 'IDR',
                  'billing_interval': 'monthly',
                },
                'add_ons': [],
              }
            }),
            200,
          );
        }
        if (request.url.path == '/api/v1/subscription/usage' || request.url.path == '/api/v1/subscription/entitlements') {
          return http.Response(jsonEncode({'data': {}}), 200);
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost', httpClient: mockClient);
      final service = SubscriptionService(apiClient: apiClient);

      final success = await service.cancelSubscription();
      expect(success, isTrue);
      expect(service.subscription?.status, equals('cancelled'));
    });
  });
}
