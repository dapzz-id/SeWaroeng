import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/loyalty/loyalty_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('LoyaltyService Tests', () {
    test('getProgram parses program configuration correctly', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/loyalty/program' && request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'data': {
                'id': 'prog-1',
                'name': 'Program Loyalitas Waroeng',
                'status': 'active',
                'points_per_currency': 1,
                'earn_unit_amount_minor': 100000,
                'minimum_earn_amount_minor': 1000000,
                'redemption_value_minor': 10000,
                'rounding_policy': 'floor',
                'is_active': true,
                'tiers_count': 3,
                'rewards_count': 2,
              },
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
      final service = LoyaltyService(apiClient: apiClient);

      final program = await service.getProgram();
      expect(program, isNotNull);
      expect(program!.name, equals('Program Loyalitas Waroeng'));
      expect(program.pointsPerCurrency, equals(1));
      expect(program.tiersCount, equals(3));
    });

    test('getCustomerAccount parses account, tier and balance', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/loyalty/customers/cust-123/account') {
          return http.Response(
            jsonEncode({
              'data': {
                'account_id': 'acct-1',
                'customer_id': 'cust-123',
                'current_balance': 250,
                'lifetime_earned': 350,
                'lifetime_redeemed': 100,
                'status': 'active',
                'current_tier': {
                  'id': 'tier-2',
                  'name': 'Silver',
                  'rank': 2,
                  'multiplier_bps': 12000,
                  'multiplier_formatted': '1.2x',
                },
                'next_tier': {
                  'name': 'Gold',
                  'min_points_earned': 500,
                  'points_needed': 150,
                },
                'active_vouchers_count': 1,
              },
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
      final service = LoyaltyService(apiClient: apiClient);

      final account = await service.getCustomerAccount('cust-123');
      expect(account, isNotNull);
      expect(account!.currentBalance, equals(250));
      expect(account.currentTier?.name, equals('Silver'));
      expect(account.nextTier?['name'], equals('Gold'));
      expect(account.activeVouchersCount, equals(1));
    });

    test('redeemReward sends reward ID and parses issued voucher', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/loyalty/customers/cust-123/redeem' && request.method == 'POST') {
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          expect(body['reward_id'], equals('rew-1'));

          return http.Response(
            jsonEncode({
              'data': {
                'id': 'vch-1',
                'code': 'VCH-ABC12345',
                'status': 'active',
                'reward_name': 'Diskon Rp 10.000',
                'discount_amount_minor': 1000000,
                'min_purchase_minor': 2000000,
                'is_valid': true,
              },
              'message': 'Reward berhasil ditukarkan!',
            }),
            201,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
      final service = LoyaltyService(apiClient: apiClient);

      final voucher = await service.redeemReward('cust-123', 'rew-1');
      expect(voucher, isNotNull);
      expect(voucher!.code, equals('VCH-ABC12345'));
      expect(voucher.discountAmountMinor, equals(1000000));
    });

    test('validateVoucher returns authoritative server discount', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/loyalty/vouchers/validate' && request.method == 'POST') {
          return http.Response(
            jsonEncode({
              'data': {
                'voucher_id': 'vch-1',
                'code': 'VCH-DISC20',
                'discount_minor': 2000000,
                'min_purchase_minor': 5000000,
              },
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
      final service = LoyaltyService(apiClient: apiClient);

      final res = await service.validateVoucher('VCH-DISC20', 10000000);
      expect(res, isNotNull);
      expect(res!['discount_minor'], equals(2000000));
    });
  });
}
