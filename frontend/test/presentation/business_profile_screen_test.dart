import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/auth/token_storage.dart';
import 'package:frontend/core/tenant/tenant_model.dart';
import 'package:frontend/core/tenant/tenant_service.dart';
import 'package:frontend/presentation/tenant/business_profile_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late InMemoryTokenStorage tokenStorage;

  setUp(() {
    tokenStorage = InMemoryTokenStorage();
  });

  testWidgets('renders business profile screen with active tenant data', (
    tester,
  ) async {
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
      id: 'tenant-123',
      name: 'Kopi SeWaroeng',
      slug: 'kopi-sewaroeng',
      businessType: 'fnb',
      status: 'active',
      role: 'owner',
      phone: '081234567890',
      email: 'owner@kopi.com',
      city: 'Jakarta Selatan',
      currency: 'IDR',
      timezone: 'Asia/Jakarta',
    );
    await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);

    await tester.pumpWidget(
      MaterialApp(home: BusinessProfileScreen(tenantService: tenantService)),
    );
    await tester.pumpAndSettle();

    expect(find.text('Profil Bisnis'), findsOneWidget);
    expect(find.text('AKTIF'), findsOneWidget);
    expect(
      find.byKey(const Key('profile_business_name_input')),
      findsOneWidget,
    );
    expect(find.text('Kopi SeWaroeng'), findsOneWidget);
    expect(find.text('kopi-sewaroeng'), findsOneWidget);
    expect(find.text('081234567890'), findsOneWidget);
    expect(find.text('owner@kopi.com'), findsOneWidget);
    expect(find.text('Jakarta Selatan'), findsOneWidget);
    expect(find.byKey(const Key('profile_save_button')), findsOneWidget);
  });

  testWidgets('successfully saves updated business profile', (tester) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    bool patchCalled = false;
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/tenants/tenant-123' &&
          request.method == 'PATCH') {
        patchCalled = true;
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['name'], equals('Kopi SeWaroeng Hebat'));
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'tenant-123',
              'name': 'Kopi SeWaroeng Hebat',
              'slug': 'kopi-sewaroeng',
              'business_type': 'fnb',
              'status': 'active',
              'currency': 'IDR',
              'timezone': 'Asia/Jakarta',
            },
            'message': 'Business profile updated successfully.',
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
      id: 'tenant-123',
      name: 'Kopi SeWaroeng',
      slug: 'kopi-sewaroeng',
      businessType: 'fnb',
      status: 'active',
    );
    await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);

    await tester.pumpWidget(
      MaterialApp(home: BusinessProfileScreen(tenantService: tenantService)),
    );
    await tester.pumpAndSettle();

    // Change name
    final nameFinder = find.byKey(const Key('profile_business_name_input'));
    await tester.enterText(nameFinder, 'Kopi SeWaroeng Hebat');

    // Click save
    final saveButton = find.byKey(const Key('profile_save_button'));
    await tester.ensureVisible(saveButton);
    await tester.tap(saveButton);
    await tester.pumpAndSettle();

    expect(patchCalled, isTrue);
    expect(find.text('Profil bisnis berhasil diperbarui.'), findsOneWidget);
  });

  testWidgets('shows error banner when API returns error', (tester) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      return http.Response(
        jsonEncode({
          'message': 'Slug sudah digunakan oleh bisnis lain.',
          'errors': {
            'slug': ['Slug ini tidak tersedia.'],
          },
        }),
        422,
      );
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
      id: 'tenant-123',
      name: 'Kopi SeWaroeng',
      slug: 'kopi-sewaroeng',
    );
    await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);

    await tester.pumpWidget(
      MaterialApp(home: BusinessProfileScreen(tenantService: tenantService)),
    );
    await tester.pumpAndSettle();

    final saveButton = find.byKey(const Key('profile_save_button'));
    await tester.ensureVisible(saveButton);
    await tester.tap(saveButton);
    await tester.pumpAndSettle();

    expect(
      find.byKey(const Key('business_profile_error_banner')),
      findsOneWidget,
    );
    expect(find.textContaining('Slug sudah digunakan'), findsOneWidget);
  });
}
