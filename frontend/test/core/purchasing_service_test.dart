import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/purchasing/purchasing_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test(
    'fetchSuppliers parses suppliers and filters activeSuppliers correctly',
    () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/purchasing/suppliers' &&
            request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'data': [
                {
                  'id': 'sup-1',
                  'tenant_id': 'tenant-1',
                  'code': 'SUP-KOPI',
                  'name': 'PT Kopi Nusantara',
                  'status': 'active',
                  'phone': '08123456789',
                },
                {
                  'id': 'sup-2',
                  'tenant_id': 'tenant-1',
                  'code': 'SUP-OLD',
                  'name': 'CV Supplier Lama',
                  'status': 'inactive',
                },
              ],
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(
        baseUrl: 'http://localhost:8000',
        httpClient: mockClient,
      );
      final service = PurchasingService(apiClient: apiClient);

      await service.fetchSuppliers();

      expect(service.suppliers.length, equals(2));
      expect(service.activeSuppliers.length, equals(1));
      expect(service.activeSuppliers.first.name, equals('PT Kopi Nusantara'));
    },
  );

  test(
    'createSupplier passes Idempotency-Key header and updates state',
    () async {
      String? capturedIdempotencyKey;
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/purchasing/suppliers' &&
            request.method == 'POST') {
          capturedIdempotencyKey = request.headers['Idempotency-Key'];
          final body = jsonDecode(request.body);
          return http.Response(
            jsonEncode({
              'data': {
                'id': 'sup-new',
                'tenant_id': 'tenant-1',
                'code': body['code'],
                'name': body['name'],
                'status': 'active',
              },
            }),
            201,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(
        baseUrl: 'http://localhost:8000',
        httpClient: mockClient,
      );
      final service = PurchasingService(apiClient: apiClient);

      final newSup = await service.createSupplier(
        code: 'SUP-SUSU',
        name: 'PT Susu Segar Sejahtera',
      );

      expect(newSup, isNotNull);
      expect(newSup?.code, equals('SUP-SUSU'));
      expect(capturedIdempotencyKey, isNotNull);
      expect(service.suppliers.length, equals(1));
    },
  );

  test(
    'fetchPurchaseOrders computes remaining quantities and formatted cost',
    () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/purchasing/orders' &&
            request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'data': [
                {
                  'id': 'po-1',
                  'tenant_id': 'tenant-1',
                  'supplier_id': 'sup-1',
                  'destination_location_id': 'loc-1',
                  'reference_number': 'PO-20261004-000001',
                  'status': 'partially_received',
                  'items': [
                    {
                      'id': 'poi-1',
                      'tenant_id': 'tenant-1',
                      'purchase_order_id': 'po-1',
                      'inventory_item_id': 'itm-1',
                      'inventory_item': {
                        'id': 'itm-1',
                        'name': 'Kopi Arabika Gayo',
                        'base_unit': 'kg',
                      },
                      'ordered_quantity': '100.0000',
                      'unit_cost_minor': 150000,
                      'received_quantity': '60.0000',
                      'remaining_quantity': '40.0000',
                    },
                  ],
                },
              ],
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(
        baseUrl: 'http://localhost:8000',
        httpClient: mockClient,
      );
      final service = PurchasingService(apiClient: apiClient);

      await service.fetchPurchaseOrders();

      expect(service.purchaseOrders.length, equals(1));
      final po = service.purchaseOrders.first;
      expect(po.isPartiallyReceived, isTrue);
      expect(po.totalOrderedQuantity, equals(100.0));
      expect(po.totalReceivedQuantity, equals(60.0));
      expect(po.totalRemainingQuantity, equals(40.0));
      expect(po.items.first.formattedUnitCost, equals('Rp 150.000'));
    },
  );

  test('postPurchaseReceipt posts GRN and records stock atomically', () async {
    String? postedId;
    String? capturedKey;
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/purchasing/receipts/grn-1/post' &&
          request.method == 'POST') {
        postedId = 'grn-1';
        capturedKey = request.headers['Idempotency-Key'];
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'grn-1',
              'tenant_id': 'tenant-1',
              'purchase_order_id': 'po-1',
              'destination_location_id': 'loc-1',
              'reference_number': 'GRN-20261004-000001',
              'status': 'posted',
              'items': [
                {
                  'id': 'gri-1',
                  'tenant_id': 'tenant-1',
                  'purchase_receipt_id': 'grn-1',
                  'purchase_order_item_id': 'poi-1',
                  'inventory_item_id': 'itm-1',
                  'quantity': '60.0000',
                  'unit_cost_minor': 150000,
                },
              ],
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
    );
    final service = PurchasingService(apiClient: apiClient);

    final postedReceipt = await service.postPurchaseReceipt('grn-1');

    expect(postedReceipt, isNotNull);
    expect(postedReceipt?.status, equals('posted'));
    expect(postedReceipt?.referenceNumber, equals('GRN-20261004-000001'));
    expect(postedId, equals('grn-1'));
    expect(capturedKey, isNotNull);
  });
}
