import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/customer/customer_service.dart';
import 'package:frontend/core/pos/pos_service.dart';
import 'package:frontend/presentation/pos/pos_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('POS customer picker selects customer and updates cart display', (tester) async {
    tester.view.physicalSize = const Size(1200, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/pos/catalog') {
        return http.Response(
          jsonEncode({
            'data': {
              'categories': [],
              'products': [],
            },
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/customers') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'cust-vip-1',
                'tenant_id': 'tenant-1',
                'name': 'Budi Santoso',
                'phone': '+6281234567890',
                'segment': 'high_value',
                'is_active': true,
              },
            ],
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final posService = PosService(apiClient: apiClient);
    final customerService = CustomerService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: PosScreen(
          posService: posService,
          customerService: customerService,
          outletId: 'out-1',
          outletName: 'Cabang Test',
        ),
      ),
    );

    await tester.pumpAndSettle();

    // Default: Pelanggan Umum (Walk-in)
    expect(find.text('Pelanggan Umum (Walk-in)'), findsOneWidget);
    expect(find.text('Pilih'), findsOneWidget);

    // Open Picker modal
    await tester.tap(find.text('Pilih'));
    await tester.pumpAndSettle();

    expect(find.text('Pilih Pelanggan POS'), findsOneWidget);
    expect(find.text('Budi Santoso'), findsOneWidget);

    // Select Budi Santoso
    await tester.tap(find.text('Budi Santoso'));
    await tester.pumpAndSettle();

    // Verify selected customer in cart
    expect(posService.selectedCustomer?.id, equals('cust-vip-1'));
    expect(find.text('Budi Santoso'), findsOneWidget);
    expect(find.text('HIGH_VALUE'), findsOneWidget);
    expect(find.text('Ganti'), findsOneWidget);

    // Clear customer
    await tester.tap(find.byTooltip('Hapus Pelanggan'));
    await tester.pumpAndSettle();

    expect(posService.selectedCustomer, isNull);
    expect(find.text('Pelanggan Umum (Walk-in)'), findsOneWidget);
  });
}
