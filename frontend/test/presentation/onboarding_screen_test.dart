import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/auth/token_storage.dart';
import 'package:frontend/core/tenant/tenant_service.dart';
import 'package:frontend/presentation/tenant/onboarding_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late InMemoryTokenStorage tokenStorage;

  setUp(() {
    tokenStorage = InMemoryTokenStorage();
  });

  testWidgets('renders onboarding form fields', (tester) async {
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

    await tester.pumpWidget(
      MaterialApp(home: OnboardingScreen(tenantService: tenantService)),
    );

    expect(find.text('Buat Bisnis Baru'), findsOneWidget);
    expect(
      find.byKey(const Key('onboarding_business_name_input')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('onboarding_business_slug_input')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('onboarding_outlet_name_input')),
      findsOneWidget,
    );
    expect(
      find.byKey(const Key('onboarding_outlet_code_input')),
      findsOneWidget,
    );
    expect(find.byKey(const Key('onboarding_submit_button')), findsOneWidget);
  });

  testWidgets('validates required fields before submitting', (tester) async {
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

    await tester.pumpWidget(
      MaterialApp(home: OnboardingScreen(tenantService: tenantService)),
    );

    // Clear default code to test validation
    await tester.enterText(
      find.byKey(const Key('onboarding_outlet_code_input')),
      '',
    );
    await tester.ensureVisible(
      find.byKey(const Key('onboarding_submit_button')),
    );
    await tester.tap(find.byKey(const Key('onboarding_submit_button')));
    await tester.pump();

    expect(find.text('Nama bisnis wajib diisi.'), findsOneWidget);
    expect(find.text('Slug bisnis wajib diisi.'), findsOneWidget);
    expect(find.text('Nama outlet wajib diisi.'), findsOneWidget);
    expect(find.text('Kode outlet wajib diisi.'), findsOneWidget);
  });

  testWidgets('displays error banner when business slug is already taken', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      return http.Response(
        jsonEncode({
          'message': 'This business slug is already taken.',
          'code': 'BUSINESS_SLUG_TAKEN',
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

    await tester.pumpWidget(
      MaterialApp(home: OnboardingScreen(tenantService: tenantService)),
    );

    await tester.enterText(
      find.byKey(const Key('onboarding_business_name_input')),
      'Waroeng Terkenal',
    );
    await tester.enterText(
      find.byKey(const Key('onboarding_business_slug_input')),
      'waroeng-terkenal',
    );
    await tester.enterText(
      find.byKey(const Key('onboarding_outlet_name_input')),
      'Pusat',
    );
    await tester.enterText(
      find.byKey(const Key('onboarding_outlet_code_input')),
      'PUSAT',
    );

    await tester.ensureVisible(
      find.byKey(const Key('onboarding_submit_button')),
    );
    await tester.tap(find.byKey(const Key('onboarding_submit_button')));
    await tester.pump(); // Submit and start request
    await tester.pump(); // Finish mock response

    expect(find.byKey(const Key('onboarding_error_banner')), findsOneWidget);
    expect(
      find.text(
        'Slug bisnis ini sudah digunakan. Harap gunakan nama atau slug lain.',
      ),
      findsOneWidget,
    );
  });

  testWidgets('successful onboarding triggers onSuccess callback', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    bool successCalled = false;

    final mockClient = MockClient((request) async {
      return http.Response(
        jsonEncode({
          'data': {
            'tenant': {
              'id': '0192a543-0000-7000-8000-000000000001',
              'name': 'Waroeng Sukses',
              'slug': 'waroeng-sukses',
              'status': 'active',
            },
            'outlet': {
              'id': '0192a543-0000-7000-8000-000000000002',
              'tenant_id': '0192a543-0000-7000-8000-000000000001',
              'name': 'Pusat',
              'code': 'PUSAT',
              'status': 'active',
            },
            'membership': {
              'id': '0192a543-0000-7000-8000-000000000003',
              'role': 'owner',
              'status': 'active',
            },
          },
          'message': 'Business created successfully.',
        }),
        201,
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

    await tester.pumpWidget(
      MaterialApp(
        home: OnboardingScreen(
          tenantService: tenantService,
          onSuccess: () => successCalled = true,
        ),
      ),
    );

    await tester.enterText(
      find.byKey(const Key('onboarding_business_name_input')),
      'Waroeng Sukses',
    );
    await tester.enterText(
      find.byKey(const Key('onboarding_business_slug_input')),
      'waroeng-sukses',
    );
    await tester.enterText(
      find.byKey(const Key('onboarding_outlet_name_input')),
      'Pusat',
    );

    await tester.ensureVisible(
      find.byKey(const Key('onboarding_submit_button')),
    );
    await tester.tap(find.byKey(const Key('onboarding_submit_button')));
    await tester.pump();
    await tester.pump();

    expect(successCalled, isTrue);
    expect(tenantService.activeTenant?.name, equals('Waroeng Sukses'));
  });
}
