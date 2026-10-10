import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/catalog/catalog_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('fetchCategories parses and updates category list', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/categories' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'cat-1',
                'tenant_id': 'tenant-1',
                'name': 'Kopi',
                'slug': 'kopi',
                'status': 'active',
              },
              {
                'id': 'cat-2',
                'tenant_id': 'tenant-1',
                'name': 'Makanan',
                'slug': 'makanan',
                'status': 'active',
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
    final service = CatalogService(apiClient: apiClient);

    final cats = await service.fetchCategories();
    expect(cats.length, equals(2));
    expect(cats[0].name, equals('Kopi'));
    expect(cats[1].name, equals('Makanan'));
    expect(service.categories.length, equals(2));
  });

  test(
    'createCategory sends idempotent request and returns new category',
    () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/categories' &&
            request.method == 'POST') {
          expect(request.headers['Idempotency-Key'], isNotEmpty);
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          expect(body['name'], equals('Camilan'));

          return http.Response(
            jsonEncode({
              'data': {
                'id': 'cat-3',
                'tenant_id': 'tenant-1',
                'name': 'Camilan',
                'slug': 'camilan',
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
      final service = CatalogService(apiClient: apiClient);

      final cat = await service.createCategory(name: 'Camilan');
      expect(cat, isNotNull);
      expect(cat!.name, equals('Camilan'));
      expect(service.categories.length, equals(1));
    },
  );

  test(
    'fetchProducts parses paginated products, variant prices and availability',
    () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/products' && request.method == 'GET') {
          expect(request.url.queryParameters['outlet_id'], equals('outlet-1'));

          return http.Response(
            jsonEncode({
              'data': {
                'items': [
                  {
                    'id': 'prod-1',
                    'tenant_id': 'tenant-1',
                    'name': 'Kopi Susu Aren',
                    'slug': 'kopi-susu-aren',
                    'product_type': 'product',
                    'status': 'active',
                    'is_available': true,
                    'price': {
                      'id': 'pr-1',
                      'tenant_id': 'tenant-1',
                      'variant_id': 'var-1',
                      'amount': 18000,
                      'currency': 'IDR',
                    },
                    'variants': [
                      {
                        'id': 'var-1',
                        'tenant_id': 'tenant-1',
                        'product_id': 'prod-1',
                        'name': 'Standard',
                        'sku': 'KSA-01',
                        'is_default': true,
                        'status': 'active',
                        'prices': [
                          {
                            'id': 'pr-1',
                            'tenant_id': 'tenant-1',
                            'variant_id': 'var-1',
                            'amount': 18000,
                            'currency': 'IDR',
                          },
                        ],
                      },
                    ],
                  },
                ],
                'pagination': {'total': 1, 'current_page': 1, 'last_page': 1},
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
      final service = CatalogService(apiClient: apiClient);

      final prods = await service.fetchProducts(outletId: 'outlet-1');
      expect(prods.length, equals(1));
      expect(prods.first.name, equals('Kopi Susu Aren'));
      expect(prods.first.formattedPrice, equals('Rp 18.000'));
      expect(prods.first.isAvailable, isTrue);
      expect(prods.first.displaySku, equals('KSA-01'));
    },
  );

  test(
    'createProduct with standard variant mode sends single atomic request',
    () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/products' &&
            request.method == 'POST') {
          expect(request.headers['Idempotency-Key'], isNotEmpty);
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          expect(body['name'], equals('Americano'));
          expect(body['price'], equals(20000));
          expect(body['sku'], equals('AME-01'));
          expect(body['barcode'], equals('89912345'));

          return http.Response(
            jsonEncode({
              'data': {
                'id': 'prod-2',
                'tenant_id': 'tenant-1',
                'name': 'Americano',
                'slug': 'americano',
                'product_type': 'product',
                'status': 'active',
                'price': {
                  'id': 'pr-2',
                  'tenant_id': 'tenant-1',
                  'variant_id': 'var-2',
                  'amount': 20000,
                  'currency': 'IDR',
                },
                'variants': [
                  {
                    'id': 'var-2',
                    'tenant_id': 'tenant-1',
                    'product_id': 'prod-2',
                    'name': 'Standard',
                    'sku': 'AME-01',
                    'is_default': true,
                    'status': 'active',
                    'barcodes': [
                      {
                        'id': 'bc-1',
                        'tenant_id': 'tenant-1',
                        'variant_id': 'var-2',
                        'barcode': '89912345',
                      },
                    ],
                  },
                ],
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
      final service = CatalogService(apiClient: apiClient);

      final created = await service.createProduct(
        name: 'Americano',
        price: 20000,
        sku: 'AME-01',
        barcode: '89912345',
      );

      expect(created, isNotNull);
      expect(created!.name, equals('Americano'));
      expect(created.formattedPrice, equals('Rp 20.000'));
      expect(service.products.length, equals(1));
    },
  );

  test('fetchModifierGroups and createModifierGroup', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/modifier-groups' &&
          request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'mg-1',
                'tenant_id': 'tenant-1',
                'name': 'Pilihan Susu',
                'selection_mode': 'single',
                'min_selection': 1,
                'max_selection': 1,
                'is_required': true,
                'modifiers': [
                  {
                    'id': 'm-1',
                    'tenant_id': 'tenant-1',
                    'modifier_group_id': 'mg-1',
                    'name': 'Fresh Milk',
                    'price_delta': 0,
                  },
                  {
                    'id': 'm-2',
                    'tenant_id': 'tenant-1',
                    'modifier_group_id': 'mg-1',
                    'name': 'Oat Milk',
                    'price_delta': 6000,
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
    final service = CatalogService(apiClient: apiClient);

    final groups = await service.fetchModifierGroups();
    expect(groups.length, equals(1));
    expect(groups.first.name, equals('Pilihan Susu'));
    expect(groups.first.modifiers.length, equals(2));
    expect(groups.first.modifiers[1].formattedPriceDelta, equals('+Rp 6.000'));
  });
}
