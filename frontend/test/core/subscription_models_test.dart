import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/subscription/subscription_models.dart';

void main() {
  group('Subscription Models Unit Tests', () {
    test('Parses TenantSubscription and SubscriptionPlan from JSON correctly', () {
      final json = {
        'id': 'sub-uuid-1',
        'tenant_id': 'tenant-uuid-1',
        'status': 'trialing',
        'is_operational': true,
        'is_trial': true,
        'trial_remaining_days': 14,
        'trial_ends_at': '2026-10-20T10:00:00Z',
        'starts_at': '2026-10-06T10:00:00Z',
        'current_period_start': '2026-10-06T10:00:00Z',
        'current_period_end': '2026-10-20T10:00:00Z',
        'cancelled_at': null,
        'plan': {
          'id': 'plan-free-id',
          'code': 'free',
          'name': 'Free / Trial',
          'description': 'Paket evaluasi',
          'price_minor': 0,
          'currency': 'IDR',
          'billing_interval': 'monthly',
        },
        'add_ons': [
          {
            'id': 'addon-assign-1',
            'code': 'addon.whatsapp',
            'name': 'Notifikasi WhatsApp',
            'quantity': 1,
            'status': 'active',
          }
        ],
      };

      final sub = TenantSubscription.fromJson(json);

      expect(sub.id, equals('sub-uuid-1'));
      expect(sub.status, equals('trialing'));
      expect(sub.isTrial, isTrue);
      expect(sub.isOperational, isTrue);
      expect(sub.trialRemainingDays, equals(14));
      expect(sub.plan.code, equals('free'));
      expect(sub.plan.name, equals('Free / Trial'));
      expect(sub.addOns.length, equals(1));
      expect(sub.addOns.first.code, equals('addon.whatsapp'));
    });

    test('Parses SubscriptionUsage and handles unlimited dimensions correctly', () {
      final json = {
        'max_outlets': {'limit': 5, 'current': 2, 'remaining': 3, 'is_unlimited': false},
        'max_users': {'limit': 15, 'current': 5, 'remaining': 10, 'is_unlimited': false},
        'max_products': {'limit': null, 'current': 150, 'remaining': null, 'is_unlimited': true},
        'max_monthly_transactions': {'limit': null, 'current': 1200, 'remaining': null, 'is_unlimited': true},
      };

      final usage = SubscriptionUsage.fromJson(json);

      expect(usage.maxOutlets.limit, equals(5));
      expect(usage.maxOutlets.current, equals(2));
      expect(usage.maxOutlets.remaining, equals(3));
      expect(usage.maxOutlets.isUnlimited, isFalse);

      expect(usage.maxProducts.limit, isNull);
      expect(usage.maxProducts.isUnlimited, isTrue);
      expect(usage.maxProducts.current, equals(150));
    });

    test('SubscriptionEntitlements evaluates can() correctly', () {
      final json = {
        'features': {
          'pos': true,
          'inventory': true,
          'offline_pos': true,
          'multi_outlet': false,
          'whatsapp': false,
        }
      };

      final entitlements = SubscriptionEntitlements.fromJson(json);

      expect(entitlements.can('pos'), isTrue);
      expect(entitlements.can('offline_pos'), isTrue);
      expect(entitlements.can('multi_outlet'), isFalse);
      expect(entitlements.can('non_existent'), isFalse);
    });
  });
}
