import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/auth/token_storage.dart';
import 'package:frontend/core/catalog/catalog_service.dart';
import 'package:frontend/core/tenant/outlet_model.dart';
import 'package:frontend/core/tenant/tenant_model.dart';
import 'package:frontend/core/tenant/tenant_service.dart';
import 'package:frontend/presentation/catalog/catalog_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late InMemoryTokenStorage tokenStorage;

  setUp(() {
    tokenStorage = InMemoryTokenStorage();
  });

  testWidgets('CatalogScreen renders product list, prices, and filter chips', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/categories') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'cat-1',
                'tenant_id': 'tenant-1',
                'name': 'Minuman Kopi',
                'slug': 'minuman-kopi',
                'status': 'active',
              },
            ],
          }),
          200,
        );
      }

      if (request.url.path == '/api/v1/products') {
        return http.Response(
          jsonEncode({
            'data': {
              'items': [
                {
                  'id': 'prod-1',
                  'tenant_id': 'tenant-1',
                  'name': 'Es Kopi Susu Senja',
                  'slug': 'es-kopi-susu-senja',
                  'product_type': 'product',
                  'status': 'active',
                  'is_available': true,
                  'category': {
                    'id': 'cat-1',
                    'tenant_id': 'tenant-1',
                    'name': 'Minuman Kopi',
                    'slug': 'minuman-kopi',
                  },
                  'price': {
                    'id': 'pr-1',
                    'tenant_id': 'tenant-1',
                    'variant_id': 'var-1',
                    'amount': 22000,
                    'currency': 'IDR',
                  },
                  'variants': [
                    {
                      'id': 'var-1',
                      'tenant_id': 'tenant-1',
                      'product_id': 'prod-1',
                      'name': 'Standard',
                      'sku': 'EKSS-01',
                      'is_default': true,
                      'status': 'active',
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

      if (request.url.path == '/api/v1/modifier-groups') {
        return http.Response(jsonEncode({'data': []}), 200);
      }

      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
    );
    final tenantService = TenantService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
    );
    const activeTenant = TenantModel(
      id: 'tenant-1',
      name: 'Waroeng Senja',
      slug: 'waroeng-senja',
    );
    const activeOutlet = OutletModel(
      id: 'outlet-1',
      tenantId: 'tenant-1',
      name: 'Pusat',
      code: 'PUSAT',
    );
    await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);
    await tenantService.setActiveOutlet(activeOutlet);

    final catalogService = CatalogService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: CatalogScreen(
          catalogService: catalogService,
          tenantService: tenantService,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Katalog Produk'), findsOneWidget);
    expect(find.text('Waroeng Senja'), findsOneWidget);
    expect(find.text('Minuman Kopi'), findsWidgets);
    expect(find.text('Es Kopi Susu Senja'), findsOneWidget);
    expect(find.text('Rp 22.000'), findsOneWidget);
    expect(find.text('Tersedia'), findsOneWidget);
    expect(find.text('Tambah Produk'), findsOneWidget);
  });

  testWidgets('CatalogScreen renders empty state when no products found', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/categories') {
        return http.Response(jsonEncode({'data': []}), 200);
      }
      if (request.url.path == '/api/v1/products') {
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
    final tenantService = TenantService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
    );
    final catalogService = CatalogService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: CatalogScreen(
          catalogService: catalogService,
          tenantService: tenantService,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Belum ada produk di katalog'), findsOneWidget);
  });
}
