import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/loyalty/loyalty_service.dart';
import 'package:frontend/presentation/loyalty/loyalty_overview_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('LoyaltyOverviewScreen displays points balance, tier, and Indonesian labels', (tester) async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/loyalty/program') {
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
              'tiers_count': 2,
              'rewards_count': 1,
            },
          }),
          200,
        );
      }

      if (request.url.path == '/loyalty/tiers') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'tier-1',
                'name': 'Bronze',
                'rank': 1,
                'min_points_earned': 0,
                'multiplier_bps': 10000,
                'multiplier_formatted': '1.0x',
                'is_active': true,
              },
              {
                'id': 'tier-2',
                'name': 'Gold',
                'rank': 2,
                'min_points_earned': 500,
                'multiplier_bps': 15000,
                'multiplier_formatted': '1.5x',
                'is_active': true,
              },
            ],
          }),
          200,
        );
      }

      if (request.url.path == '/loyalty/rewards') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'rew-1',
                'name': 'Kopi Gratis',
                'description': 'Tukar segelas kopi susu gula aren',
                'points_cost': 100,
                'reward_type': 'discount_fixed',
                'discount_amount_minor': 1500000,
                'min_purchase_minor': 0,
                'stock': 10,
                'validity_days': 30,
                'is_active': true,
              },
            ],
          }),
          200,
        );
      }

      if (request.url.path == '/loyalty/customers/cust-1/account') {
        return http.Response(
          jsonEncode({
            'data': {
              'account_id': 'acct-1',
              'customer_id': 'cust-1',
              'current_balance': 1250,
              'lifetime_earned': 2000,
              'lifetime_redeemed': 750,
              'status': 'active',
              'current_tier': {
                'id': 'tier-2',
                'name': 'Gold',
                'rank': 2,
                'multiplier_bps': 15000,
                'multiplier_formatted': '1.5x',
              },
              'next_tier': {
                'name': 'Platinum',
                'min_points_earned': 2750,
                'points_needed': 750,
              },
              'active_vouchers_count': 1,
            },
          }),
          200,
        );
      }

      if (request.url.path == '/loyalty/customers/cust-1/vouchers') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'vch-1',
                'code': 'VCH-PROMO1',
                'status': 'active',
                'reward_name': 'Diskon Rp 15.000',
                'discount_amount_minor': 1500000,
                'min_purchase_minor': 3000000,
                'is_valid': true,
                'expires_at': '2026-11-01T00:00:00Z',
              },
            ],
          }),
          200,
        );
      }

      if (request.url.path.startsWith('/loyalty/customers/cust-1/ledger')) {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'led-1',
                'type': 'purchase',
                'points': 50,
                'balance_after': 1250,
                'notes': 'Transaksi Pembelian #INV-001',
                'created_at': '2026-10-07T12:00:00Z',
              },
            ],
          }),
          200,
        );
      }

      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final loyaltyService = LoyaltyService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: LoyaltyOverviewScreen(
          loyaltyService: loyaltyService,
          customerId: 'cust-1',
          customerName: 'Ahmad Pelanggan',
        ),
      ),
    );

    // Initial loading
    expect(find.byType(CircularProgressIndicator), findsOneWidget);

    await tester.pumpAndSettle();

    // Verify Title & Customer Name
    expect(find.text('Loyalitas: Ahmad Pelanggan'), findsOneWidget);

    // Verify Points display (Indonesian formatting)
    expect(find.text('Poin Anda'), findsOneWidget);
    expect(find.text('1.250 poin'), findsOneWidget);

    // Verify Tier name
    expect(find.text('Gold'), findsAtLeastNWidgets(1));

    // Verify Next tier prompt
    expect(find.text('Menuju Platinum:'), findsOneWidget);
    expect(find.text('750 poin lagi'), findsOneWidget);

    // Switch to Katalog Reward Tab
    await tester.tap(find.text('Katalog Reward'));
    await tester.pumpAndSettle();

    expect(find.text('Kopi Gratis'), findsOneWidget);
    expect(find.text('100 poin'), findsOneWidget);
    expect(find.text('Tukar'), findsOneWidget);
  });
}
