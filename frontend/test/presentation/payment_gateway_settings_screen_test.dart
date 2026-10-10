import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/payment/payment_service.dart';
import 'package:frontend/presentation/platform/payment_gateway_settings_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('renders payment gateway settings screen with providers list', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      if (request.url.path.contains('/platform/payment-providers')) {
        return http.Response(
          jsonEncode({
            'status': 'success',
            'data': [
              {
                'id': 'prov-1',
                'provider': 'midtrans',
                'display_name': 'Midtrans Payment Gateway',
                'is_enabled': true,
                'supported_methods': ['qris', 'bank_transfer', 'card', 'e_wallet'],
                'capabilities': {'refund': true, 'status_inquiry': true},
              },
              {
                'id': 'prov-2',
                'provider': 'duitku',
                'display_name': 'Duitku Payment Gateway',
                'is_enabled': true,
                'supported_methods': ['qris', 'bank_transfer', 'e_wallet', 'retail'],
                'capabilities': {'refund': false, 'status_inquiry': true},
              },
            ],
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      return http.Response('{"status":"success","data":[]}', 200, headers: {'content-type': 'application/json'});
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final paymentService = PaymentGatewayService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: PaymentGatewaySettingsScreen(
          paymentService: paymentService,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Superadmin Payment Gateway Settings'), findsOneWidget);
    expect(find.text('Platform Payment Gateways'), findsOneWidget);
    expect(find.text('Midtrans Payment Gateway'), findsOneWidget);
    expect(find.text('Duitku Payment Gateway'), findsOneWidget);
    expect(find.text('Methods: qris, bank_transfer, card, e_wallet'), findsOneWidget);
    expect(find.text('Methods: qris, bank_transfer, e_wallet, retail'), findsOneWidget);
  });
}
