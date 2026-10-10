import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/receipt/receipt_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('getReceipt parses structured presentation data from server', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/pos/sales/sale-123/receipt' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'receipt_number': 'REC-202610-001',
              'sale_reference_number': 'SAL-202610-001',
              'issued_at': '2026-10-06T10:00:00Z',
              'reprint_count': 0,
              'business': {'name': 'Waroeng Kopi Enak'},
              'outlet': {'name': 'Cabang Utama', 'address': 'Jl. Merdeka No. 10', 'phone': '08123456789'},
              'cashier_name': 'Kasir Budi',
              'customer': {'name': 'Pelanggan Setia', 'phone': '+6281299998888'},
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
              'is_cancelled': false,
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = ReceiptService(apiClient: apiClient);

    final receipt = await service.getReceipt('sale-123');

    expect(receipt, isNotNull);
    expect(receipt!.receiptNumber, equals('REC-202610-001'));
    expect(receipt.businessName, equals('Waroeng Kopi Enak'));
    expect(receipt.outletName, equals('Cabang Utama'));
    expect(receipt.customerName, equals('Pelanggan Setia'));
    expect(receipt.customerPhone, equals('+6281299998888'));
    expect(receipt.items.length, equals(1));
    expect(receipt.items.first.name, equals('Es Kopi Susu'));
    expect(receipt.totalMinor, equals(3600000));
    expect(receipt.payments.first.method, equals('cash'));
    expect(receipt.payments.first.changeMinor, equals(400000));
    expect(receipt.isReprint, isFalse);
  });

  test('reprintReceipt increments reprint count and returns updated model', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/pos/sales/sale-123/receipt/reprint' && request.method == 'POST') {
        return http.Response(
          jsonEncode({
            'data': {
              'receipt_number': 'REC-202610-001',
              'sale_reference_number': 'SAL-202610-001',
              'issued_at': '2026-10-06T10:00:00Z',
              'reprint_count': 1,
              'business': {'name': 'Waroeng Kopi Enak'},
              'outlet': {'name': 'Cabang Utama'},
              'cashier_name': 'Kasir Budi',
              'items': [],
              'pricing': {'total_minor': 3600000},
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
    final service = ReceiptService(apiClient: apiClient);

    final receipt = await service.reprintReceipt('sale-123');

    expect(receipt, isNotNull);
    expect(receipt!.reprintCount, equals(1));
    expect(receipt.isReprint, isTrue);
  });
}
