import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/auth/token_storage.dart';
import 'package:frontend/core/catalog/catalog_service.dart';
import 'package:frontend/core/tenant/tenant_model.dart';
import 'package:frontend/core/tenant/tenant_service.dart';
import 'package:frontend/presentation/catalog/product_form_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late InMemoryTokenStorage tokenStorage;

  setUp(() {
    tokenStorage = InMemoryTokenStorage();
  });

  testWidgets(
    'ProductFormScreen renders standard mode fields and validates name',
    (tester) async {
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
      await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);

      final catalogService = CatalogService(apiClient: apiClient);

      await tester.pumpWidget(
        MaterialApp(
          home: ProductFormScreen(
            catalogService: catalogService,
            tenantService: tenantService,
          ),
        ),
      );
      await tester.pumpAndSettle();

      expect(find.text('Tambah Produk Baru'), findsOneWidget);
      expect(find.text('Nama Produk *'), findsOneWidget);
      expect(find.text('Harga Jual Standar (Rp) *'), findsOneWidget);

      // Tap Simpan with empty name -> triggers validation
      await tester.tap(find.text('Simpan'));
      await tester.pumpAndSettle();

      expect(find.text('Nama produk wajib diisi'), findsOneWidget);
    },
  );

  testWidgets('ProductFormScreen switches to Multi-Varian mode', (
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
    final catalogService = CatalogService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: ProductFormScreen(
          catalogService: catalogService,
          tenantService: tenantService,
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Tap Multi-Varian segment
    await tester.tap(find.text('Multi-Varian'));
    await tester.pumpAndSettle();

    expect(find.text('Tambah Varian Lain'), findsOneWidget);
    expect(find.text('Varian Default'), findsOneWidget);
  });
}
