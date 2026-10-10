import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/auth/token_storage.dart';
import 'package:frontend/core/shift/shift_service.dart';
import 'package:frontend/core/tenant/outlet_model.dart';
import 'package:frontend/core/tenant/tenant_model.dart';
import 'package:frontend/core/tenant/tenant_service.dart';
import 'package:frontend/presentation/shift/shift_management_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late InMemoryTokenStorage tokenStorage;

  setUp(() {
    tokenStorage = InMemoryTokenStorage();
  });

  testWidgets('renders closed state with open shift inputs when no active shift', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      if (request.url.path.contains('/shifts/current')) {
        return http.Response(
          jsonEncode({
            'data': null,
            'message': 'No active shift found for this outlet.',
          }),
          200,
        );
      }
      return http.Response('{"data": null}', 200);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final tenantService = TenantService(apiClient: apiClient, tokenStorage: tokenStorage);

    const activeTenant = TenantModel(
      id: 'tenant-1',
      name: 'Waroeng Test',
      slug: 'waroeng-test',
      businessType: 'fnb',
      status: 'active',
      role: 'owner',
    );
    await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);

    const activeOutlet = OutletModel(
      id: 'out-1',
      tenantId: 'tenant-1',
      name: 'Cabang Utama',
      code: 'OUT-01',
      status: 'active',
    );
    tenantService.setActiveOutlet(activeOutlet);

    final shiftService = ShiftService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: ShiftManagementScreen(
          shiftService: shiftService,
          tenantService: tenantService,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('Manajemen Shift & Kasir'), findsOneWidget);
    expect(find.text('Laci Kasir Belum Dibuka'), findsOneWidget);
    expect(find.byKey(const Key('shift_opening_cash_input')), findsOneWidget);
    expect(find.byKey(const Key('shift_open_button')), findsOneWidget);
  });

  testWidgets('renders active shift view with expected cash and action buttons', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      if (request.url.path.contains('/shifts/current')) {
        return http.Response(
          jsonEncode({
            'data': {
              'shift': {
                'id': 'shift-active-1',
                'tenant_id': 'tenant-1',
                'outlet_id': 'out-1',
                'opened_by_user_id': 'user-1',
                'status': 'open',
                'opened_at': '2026-10-05T08:00:00.000Z',
                'opening_cash_minor': 100000,
                'opened_by': {'name': 'Budi Kasir'},
                'movements': [
                  {
                    'id': 'mov-1',
                    'tenant_id': 'tenant-1',
                    'outlet_id': 'out-1',
                    'shift_id': 'shift-active-1',
                    'type': 'opening',
                    'direction': 'in',
                    'amount_minor': 100000,
                    'reason': 'Opening Cash Float',
                    'performed_by_user_id': 'user-1',
                    'created_at': '2026-10-05T08:00:00.000Z',
                  }
                ],
              },
              'summary': {
                'opening_cash': 100000,
                'cash_sales': 50000,
                'cash_in': 0,
                'cash_out': 0,
                'cash_refund': 0,
                'adjustments': 0,
                'expected_closing': 150000,
                'movement_count': 2,
              }
            }
          }),
          200,
        );
      }
      return http.Response('{"data": null}', 200);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final tenantService = TenantService(apiClient: apiClient, tokenStorage: tokenStorage);

    const activeTenant = TenantModel(
      id: 'tenant-1',
      name: 'Waroeng Test',
      slug: 'waroeng-test',
      businessType: 'fnb',
      status: 'active',
      role: 'owner',
    );
    await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);

    const activeOutlet = OutletModel(
      id: 'out-1',
      tenantId: 'tenant-1',
      name: 'Cabang Utama',
      code: 'OUT-01',
      status: 'active',
    );
    tenantService.setActiveOutlet(activeOutlet);

    final shiftService = ShiftService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: ShiftManagementScreen(
          shiftService: shiftService,
          tenantService: tenantService,
        ),
      ),
    );
    await tester.pumpAndSettle();

    expect(find.text('SHIFT AKTIF'), findsOneWidget);
    expect(find.byKey(const Key('shift_expected_cash_text')), findsOneWidget);
    expect(find.text('Rp 150.000'), findsWidgets);
    expect(find.byKey(const Key('shift_cash_in_button')), findsOneWidget);
    expect(find.byKey(const Key('shift_cash_out_button')), findsOneWidget);
    expect(find.byKey(const Key('shift_close_button')), findsOneWidget);

    // Tap Close Shift button to verify modal dialog appears
    await tester.tap(find.byKey(const Key('shift_close_button')));
    await tester.pumpAndSettle();

    expect(find.text('Tutup Shift & Hitung Uang Fisik'), findsOneWidget);
    expect(find.byKey(const Key('shift_close_actual_cash_input')), findsOneWidget);
    expect(find.byKey(const Key('shift_confirm_close_button')), findsOneWidget);
  });
}
