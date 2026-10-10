import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/auth/token_storage.dart';
import 'package:frontend/core/tenant/outlet_model.dart';
import 'package:frontend/core/tenant/tenant_model.dart';
import 'package:frontend/core/tenant/tenant_service.dart';
import 'package:frontend/presentation/outlet/outlet_management_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late InMemoryTokenStorage tokenStorage;

  setUp(() {
    tokenStorage = InMemoryTokenStorage();
  });

  testWidgets('renders outlet list and active outlet badge', (tester) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000');
    final tenantService = TenantService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
    );

    const activeTenant = TenantModel(
      id: 'tenant-1',
      name: 'Kopi SeWaroeng',
      slug: 'kopi-sewaroeng',
    );
    const outlet1 = OutletModel(
      id: 'outlet-1',
      tenantId: 'tenant-1',
      name: 'Outlet Pusat',
      code: 'PUSAT',
      status: 'active',
      city: 'Jakarta Selatan',
    );
    const outlet2 = OutletModel(
      id: 'outlet-2',
      tenantId: 'tenant-1',
      name: 'Outlet Cabang Tebet',
      code: 'TEBET',
      status: 'inactive',
      city: 'Jakarta Selatan',
    );

    await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);
    tenantService.setOutletsForTesting([outlet1, outlet2]);
    await tenantService.setActiveOutlet(outlet1);

    await tester.pumpWidget(
      MaterialApp(home: OutletManagementScreen(tenantService: tenantService)),
    );
    await tester.pumpAndSettle();

    expect(find.text('Kelola Outlet'), findsOneWidget);
    expect(find.text('Outlet Pusat'), findsOneWidget);
    expect(find.text('PUSAT'), findsOneWidget);
    expect(find.text('AKTIF'), findsOneWidget);
    expect(find.text('Outlet Aktif Saat Ini'), findsOneWidget);

    expect(find.text('Outlet Cabang Tebet'), findsOneWidget);
    expect(find.text('TEBET'), findsOneWidget);
    expect(find.text('NONAKTIF'), findsOneWidget);
  });

  testWidgets('can select another active outlet', (tester) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000');
    final tenantService = TenantService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
    );

    const activeTenant = TenantModel(
      id: 'tenant-1',
      name: 'Kopi SeWaroeng',
      slug: 'kopi-sewaroeng',
    );
    const outlet1 = OutletModel(
      id: 'outlet-1',
      tenantId: 'tenant-1',
      name: 'Outlet Pusat',
      code: 'PUSAT',
      status: 'active',
    );
    const outlet2 = OutletModel(
      id: 'outlet-2',
      tenantId: 'tenant-1',
      name: 'Outlet Cabang Tebet',
      code: 'TEBET',
      status: 'active',
    );

    await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);
    tenantService.setOutletsForTesting([outlet1, outlet2]);
    await tenantService.setActiveOutlet(outlet1);

    await tester.pumpWidget(
      MaterialApp(home: OutletManagementScreen(tenantService: tenantService)),
    );
    await tester.pumpAndSettle();

    final selectButton = find.byKey(const Key('select_active_outlet_outlet-2'));
    expect(selectButton, findsOneWidget);
    await tester.tap(selectButton);
    await tester.pumpAndSettle();

    expect(tenantService.activeOutlet?.id, equals('outlet-2'));
    expect(
      find.text('Outlet aktif diubah ke "Outlet Cabang Tebet".'),
      findsOneWidget,
    );
  });

  testWidgets('toggle outlet status calls API and updates status', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    bool toggleCalled = false;
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/outlets/outlet-1' &&
          request.method == 'PATCH') {
        toggleCalled = true;
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['status'], equals('inactive'));
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'outlet-1',
              'tenant_id': 'tenant-1',
              'name': 'Outlet Pusat',
              'code': 'PUSAT',
              'status': 'inactive',
            },
            'message': 'Outlet updated successfully.',
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

    const activeTenant = TenantModel(
      id: 'tenant-1',
      name: 'Kopi SeWaroeng',
      slug: 'kopi-sewaroeng',
    );
    const outlet1 = OutletModel(
      id: 'outlet-1',
      tenantId: 'tenant-1',
      name: 'Outlet Pusat',
      code: 'PUSAT',
      status: 'active',
    );

    await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);
    tenantService.setOutletsForTesting([outlet1]);

    await tester.pumpWidget(
      MaterialApp(home: OutletManagementScreen(tenantService: tenantService)),
    );
    await tester.pumpAndSettle();

    final toggleButton = find.byKey(const Key('toggle_status_outlet-1'));
    await tester.tap(toggleButton);
    await tester.pumpAndSettle();

    expect(toggleCalled, isTrue);
    expect(
      find.text('Outlet "Outlet Pusat" telah dinonaktifkan.'),
      findsOneWidget,
    );
    expect(tenantService.outlets.first.status, equals('inactive'));
  });

  testWidgets('can open outlet creation form and submit new outlet', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    bool postCalled = false;
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/outlets' && request.method == 'POST') {
        postCalled = true;
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['name'], equals('Outlet Baru'));
        expect(body['code'], equals('BARU01'));
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'outlet-new-1',
              'tenant_id': 'tenant-1',
              'name': 'Outlet Baru',
              'code': 'BARU01',
              'status': 'active',
            },
            'message': 'Outlet created successfully.',
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
    final tenantService = TenantService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
    );

    const activeTenant = TenantModel(
      id: 'tenant-1',
      name: 'Kopi SeWaroeng',
      slug: 'kopi-sewaroeng',
    );
    await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);
    tenantService.setOutletsForTesting([]);

    await tester.pumpWidget(
      MaterialApp(home: OutletManagementScreen(tenantService: tenantService)),
    );
    await tester.pumpAndSettle();

    // Click FAB to open form
    final fab = find.byKey(const Key('add_outlet_fab'));
    await tester.tap(fab);
    await tester.pumpAndSettle();

    expect(find.text('Tambah Outlet Baru'), findsOneWidget);

    // Enter name & code
    await tester.enterText(
      find.byKey(const Key('outlet_form_name_input')),
      'Outlet Baru',
    );
    await tester.enterText(
      find.byKey(const Key('outlet_form_code_input')),
      'BARU01',
    );

    // Submit form
    final submitButton = find.byKey(const Key('outlet_form_submit_button'));
    await tester.ensureVisible(submitButton);
    await tester.tap(submitButton);
    await tester.pumpAndSettle();

    expect(postCalled, isTrue);
    expect(find.text('Outlet baru berhasil ditambahkan.'), findsOneWidget);
  });
}
