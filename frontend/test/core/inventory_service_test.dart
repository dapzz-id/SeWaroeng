import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/inventory/inventory_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('fetchLocations parses and sets default selected location', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/inventory/locations' &&
          request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'loc-1',
                'tenant_id': 'tenant-1',
                'name': 'Gudang Pusat',
                'code': 'GDG-01',
                'type': 'warehouse',
                'is_active': true,
              },
              {
                'id': 'loc-2',
                'tenant_id': 'tenant-1',
                'name': 'Outlet Utama',
                'code': 'OUT-01',
                'type': 'outlet',
                'is_active': true,
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
    final service = InventoryService(apiClient: apiClient);

    await service.fetchLocations();

    expect(service.locations.length, equals(2));
    expect(service.locations[0].name, equals('Gudang Pusat'));
    expect(service.selectedLocation?.id, equals('loc-1'));
  });

  test(
    'fetchItems parses items and computes low stock and negative counts',
    () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/inventory/items' &&
            request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'data': {
                'items': [
                  {
                    'id': 'item-1',
                    'tenant_id': 'tenant-1',
                    'name': 'Biji Kopi Arabika',
                    'sku': 'BKA-01',
                    'base_unit': 'kg',
                    'item_type': 'sellable',
                    'status': 'active',
                    'total_on_hand': 20.0,
                    'total_reserved': 2.0,
                    'total_available': 18.0,
                    'location_balance': {
                      'location_id': 'loc-1',
                      'on_hand': 20.0,
                      'reserved': 2.0,
                      'available': 18.0,
                      'reorder_level': 25.0,
                      'is_low_stock': true,
                    },
                  },
                  {
                    'id': 'item-2',
                    'tenant_id': 'tenant-1',
                    'name': 'Gula Pasir',
                    'sku': 'GLA-01',
                    'base_unit': 'kg',
                    'item_type': 'raw_material',
                    'status': 'active',
                    'total_on_hand': -1.5,
                    'total_reserved': 0.0,
                    'total_available': -1.5,
                    'location_balance': {
                      'location_id': 'loc-1',
                      'on_hand': -1.5,
                      'reserved': 0.0,
                      'available': -1.5,
                      'reorder_level': 10.0,
                      'is_low_stock': true,
                    },
                  },
                ],
                'pagination': {'total': 2, 'current_page': 1, 'last_page': 1},
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
      final service = InventoryService(apiClient: apiClient);

      await service.fetchItems(locationId: 'loc-1');

      expect(service.items.length, equals(2));
      expect(service.lowStockCount, equals(2));
      expect(service.negativeStockCount, equals(1));
      expect(service.items[0].effectiveAvailable, equals('18.00'));
      expect(service.items[1].isNegative, isTrue);
    },
  );

  test('adjustStock sends delta, reason and Idempotency-Key', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/inventory/adjustments' &&
          request.method == 'POST') {
        expect(request.headers['Idempotency-Key'], isNotEmpty);
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['inventory_item_id'], equals('item-1'));
        expect(body['quantity_delta'], equals(-2.5));
        expect(body['reason'], equals('damaged'));

        return http.Response(
          jsonEncode({
            'data': {
              'id': 'mov-1',
              'movement_number': 'INV-20261004-001',
              'quantity_delta': '-2.5000',
              'after_on_hand': '15.5000',
            },
          }),
          201,
        );
      }
      if (request.url.path == '/api/v1/inventory/items' &&
          request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'items': [],
              'pagination': {'total': 0, 'current_page': 1, 'last_page': 1},
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
    final service = InventoryService(apiClient: apiClient);

    final success = await service.adjustStock(
      itemId: 'item-1',
      locationId: 'loc-1',
      delta: -2.5,
      reason: 'damaged',
      note: 'Kemasan sobek',
    );

    expect(success, isTrue);
  });

  test('insufficient stock error from backend maps to errorMessage', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/inventory/adjustments' &&
          request.method == 'POST') {
        return http.Response(
          jsonEncode({
            'message': 'Insufficient stock for this inventory item.',
            'code': 'INSUFFICIENT_STOCK',
          }),
          422,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
    );
    final service = InventoryService(apiClient: apiClient);

    final success = await service.adjustStock(
      itemId: 'item-1',
      locationId: 'loc-1',
      delta: -100.0,
      reason: 'correction',
    );

    expect(success, isFalse);
    expect(service.errorMessage, contains('Insufficient stock'));
  });

  test('approveOpname sends approval and triggers opnames refresh', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/inventory/opnames/op-1/approve' &&
          request.method == 'POST') {
        expect(request.headers['Idempotency-Key'], isNotEmpty);
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'op-1',
              'opname_number': 'OPN-20261004-001',
              'status': 'approved',
            },
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/inventory/opnames' &&
          request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'items': [
                {
                  'id': 'op-1',
                  'opname_number': 'OPN-20261004-001',
                  'status': 'approved',
                },
              ],
            },
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/inventory/items' &&
          request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'items': [],
              'pagination': {'total': 0, 'current_page': 1, 'last_page': 1},
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
    final service = InventoryService(apiClient: apiClient);

    final success = await service.approveOpname('op-1');
    expect(success, isTrue);
  });
}
