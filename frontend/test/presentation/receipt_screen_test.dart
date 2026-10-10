import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/receipt/receipt_service.dart';
import 'package:frontend/core/whatsapp/whatsapp_service.dart';
import 'package:frontend/presentation/pos/receipt_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('renders receipt details correctly', (tester) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      if (request.url.path.contains('/receipt')) {
        return http.Response(
          jsonEncode({
            'data': {
              'receipt_number': 'REC-202610-999',
              'sale_reference_number': 'SAL-202610-999',
              'issued_at': '2026-10-06T10:00:00Z',
              'reprint_count': 0,
              'business': {'name': 'Waroeng Sejahtera'},
              'outlet': {'name': 'Outlet Utama', 'address': 'Jl. Kopi Enak No. 1'},
              'cashier_name': 'Budi Kasir',
              'customer': {'name': 'Ahmad Pelanggan', 'phone': '+628123456789'},
              'items': [
                {
                  'name': 'Es Kopi Susu',
                  'variant_name': 'Reguler',
                  'quantity': 2,
                  'unit_price_minor': 1800000,
                  'discount_minor': 0,
                  'subtotal_minor': 3600000,
                },
              ],
              'pricing': {
                'subtotal_minor': 3600000,
                'discount_minor': 0,
                'tax_minor': 0,
                'total_minor': 3600000,
              },
              'payments': [
                {
                  'method': 'cash',
                  'amount_minor': 4000000,
                  'status': 'succeeded',
                  'paid_at': '2026-10-06T10:00:00Z',
                  'change_minor': 400000,
                },
              ],
              'status': 'finalized',
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final receiptService = ReceiptService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: ReceiptScreen(
          saleId: 'sale-999',
          receiptService: receiptService,
        ),
      ),
    );

    // Allow FutureBuilder / initState to finish
    await tester.pumpAndSettle();

    expect(find.text('Waroeng Sejahtera'), findsOneWidget);
    expect(find.text('Outlet Utama'), findsOneWidget);
    expect(find.text('REC-202610-999'), findsOneWidget);
    expect(find.text('SAL-202610-999'), findsOneWidget);
    expect(find.text('Budi Kasir'), findsOneWidget);
    expect(find.text('Ahmad Pelanggan (+628123456789)'), findsOneWidget);
    expect(find.text('Es Kopi Susu (Reguler)'), findsOneWidget);
    expect(find.text('Metode: CASH'), findsOneWidget);
    expect(find.text('Cetak Ulang'), findsOneWidget);
  });

  testWidgets('renders WhatsApp button and sends receipt when whatsAppService is provided', (tester) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    bool waReceiptSent = false;
    final mockClient = MockClient((request) async {
      if (request.url.path.contains('/whatsapp/receipt/')) {
        waReceiptSent = true;
        return http.Response(jsonEncode({'message': 'Queued'}), 200);
      }
      if (request.url.path.contains('/pos/sales/') && request.url.path.contains('/receipt')) {
        return http.Response(
          jsonEncode({
            'data': {
              'receipt_number': 'REC-202610-999',
              'sale_reference_number': 'SAL-202610-999',
              'issued_at': '2026-10-06T10:00:00Z',
              'reprint_count': 0,
              'business': {'name': 'Waroeng Sejahtera'},
              'outlet': {'name': 'Outlet Utama'},
              'cashier_name': 'Budi Kasir',
              'customer': {'name': 'Ahmad Pelanggan', 'phone': '+628123456789'},
              'items': [],
              'pricing': {'subtotal_minor': 1000, 'discount_minor': 0, 'tax_minor': 0, 'total_minor': 1000},
              'payments': [],
              'status': 'finalized',
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final receiptService = ReceiptService(apiClient: apiClient);
    final whatsAppService = WhatsAppService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: ReceiptScreen(
          saleId: 'sale-999',
          receiptService: receiptService,
          whatsAppService: whatsAppService,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('WhatsApp'), findsOneWidget);
    await tester.tap(find.text('WhatsApp'));
    await tester.pumpAndSettle();

    expect(waReceiptSent, isTrue);
    expect(find.text('Nota transaksi berhasil dikirim ke WhatsApp pelanggan.'), findsOneWidget);
  });
}
