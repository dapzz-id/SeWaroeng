import 'dart:convert';
import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/auth/auth_service.dart';
import 'package:frontend/core/auth/token_storage.dart';
import 'package:frontend/core/dashboard/dashboard_service.dart';
import 'package:frontend/core/device/device_service.dart';
import 'package:frontend/core/notification/notification_service.dart';
import 'package:frontend/core/tenant/tenant_model.dart';
import 'package:frontend/core/tenant/tenant_service.dart';
import 'package:frontend/presentation/ai/ai_copilot_screen.dart';
import 'package:frontend/presentation/customer/customer_list_screen.dart';
import 'package:frontend/presentation/dashboard/dashboard_placeholder_screen.dart';
import 'package:frontend/presentation/employee/employee_management_screen.dart';
import 'package:frontend/presentation/finance/payment_reconciliation_screen.dart';
import 'package:frontend/presentation/order/order_management_screen.dart';
import 'package:frontend/presentation/platform/payment_gateway_settings_screen.dart';
import 'package:frontend/presentation/platform/whatsapp_settings_screen.dart';
import 'package:frontend/presentation/recipe/recipe_list_screen.dart';
import 'package:frontend/presentation/table/booking_management_screen.dart';
import 'package:frontend/presentation/table/table_management_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late Directory tempDir;
  late DeviceService deviceService;

  setUp(() async {
    tempDir = await Directory.systemTemp.createTemp('feat_nav_test_');
    deviceService = DeviceService(tempDir);
  });

  tearDown(() async {
    try {
      if (await tempDir.exists()) {
        await tempDir.delete(recursive: true);
      }
    } catch (_) {}
  });

  testWidgets('Dashboard integrates navigation to all 6 feature areas and opens them correctly', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      final path = request.url.path;

      if (path == '/api/v1/dashboard') {
        return http.Response(
          jsonEncode({
            'data': {
              'period': {'name': 'today'},
              'kpi': {
                'gross_sales_minor': 10000000,
                'refunds_minor': 0,
                'net_sales_minor': 10000000,
                'transaction_count': 50,
                'average_order_value_minor': 200000,
              },
              'top_products': [],
              'low_stock': [],
              'top_customers': [],
              'payment_summary': {},
              'outlet_summary': [],
              'alerts': {'unread_count': 0, 'has_critical': false},
            },
          }),
          200,
        );
      }
      if (path == '/api/v1/notifications/unread-count') {
        return http.Response(jsonEncode({'data': {'unread_count': 0}}), 200);
      }
      if (path == '/api/v1/employees' || path == '/api/v1/employees/roles') {
        return http.Response(jsonEncode({'data': []}), 200);
      }
      if (path == '/api/v1/customers') {
        return http.Response(jsonEncode({'data': []}), 200);
      }
      if (path == '/api/v1/tables') {
        return http.Response(jsonEncode({'data': []}), 200);
      }
      if (path == '/api/v1/bookings') {
        return http.Response(jsonEncode({'data': []}), 200);
      }
      if (path == '/api/v1/orders') {
        return http.Response(jsonEncode({'data': []}), 200);
      }
      if (path == '/api/v1/recipes') {
        return http.Response(jsonEncode({'data': []}), 200);
      }
      if (path.startsWith('/api/v1/whatsapp')) {
        return http.Response(
          jsonEncode({
            'data': {
              'connection': {
                'id': 'conn-1',
                'tenant_id': 'tenant-123',
                'provider': 'meta_cloud',
                'environment': 'sandbox',
                'phone_number_id': '12345',
                'business_account_id': '67890',
                'is_active': true,
              },
              'messages': [],
              'preferences': {
                'daily_sales_summary': true,
                'low_stock': true,
                'booking_reminder': true,
                'payment_settlement': true,
                'anomaly_alert': true,
              },
            },
          }),
          200,
        );
      }
      if (path.startsWith('/api/v1/payment-reconciliation')) {
        return http.Response(
          jsonEncode({
            'data': [],
            'summary': {
              'total_transactions': 0,
              'total_amount_minor': 0,
              'matched_count': 0,
              'discrepancy_count': 0,
              'unmatched_count': 0,
            },
          }),
          200,
        );
      }
      if (path.startsWith('/platform') || path.startsWith('/api/v1/platform')) {
        return http.Response(jsonEncode({'data': []}), 200);
      }

      return http.Response(jsonEncode({'data': []}), 200);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final tokenStorage = InMemoryTokenStorage();
    final tenantService = TenantService(apiClient: apiClient, tokenStorage: tokenStorage);
    final authService = AuthService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
      deviceService: deviceService,
    );

    const activeTenant = TenantModel(
      id: 'tenant-123',
      name: 'Waroeng Sejahtera',
      slug: 'waroeng-sejahtera',
      businessType: 'fnb',
      status: 'active',
      role: 'owner',
      phone: '081234567890',
      email: 'owner@sejahtera.com',
      city: 'Jakarta',
      currency: 'IDR',
      timezone: 'Asia/Jakarta',
    );
    await tenantService.setActiveTenant(activeTenant, reloadOutlets: false);

    final dashboardService = DashboardService(apiClient: apiClient);
    final notificationService = NotificationService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: DashboardPlaceholderScreen(
          tenantService: tenantService,
          authService: authService,
          dashboardService: dashboardService,
          notificationService: notificationService,
        ),
      ),
    );
    await tester.pumpAndSettle();

    // 1. Verify Quick Action Buttons Exist
    expect(find.byKey(const Key('dashboard_pos_button')), findsOneWidget);
    expect(find.byKey(const Key('dashboard_orders_button')), findsOneWidget);
    expect(find.byKey(const Key('dashboard_tables_button')), findsOneWidget);
    expect(find.byKey(const Key('dashboard_bookings_button')), findsOneWidget);
    expect(find.byKey(const Key('dashboard_recipes_button')), findsOneWidget);
    expect(find.byKey(const Key('dashboard_customers_button')), findsOneWidget);
    expect(find.byKey(const Key('dashboard_ai_copilot_button')), findsOneWidget);
    expect(find.byKey(const Key('dashboard_employees_button')), findsOneWidget);

    // 2. Test Customer Navigation
    await tester.ensureVisible(find.byKey(const Key('dashboard_customers_button')));
    await tester.tap(find.byKey(const Key('dashboard_customers_button')));
    await tester.pumpAndSettle();
    expect(find.byType(CustomerListScreen), findsOneWidget);
    // Pop back
    await tester.pageBack();
    await tester.pumpAndSettle();

    // 3. Test Recipe Navigation
    await tester.ensureVisible(find.byKey(const Key('dashboard_recipes_button')));
    await tester.tap(find.byKey(const Key('dashboard_recipes_button')));
    await tester.pumpAndSettle();
    expect(find.byType(RecipeListScreen), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    // 4. Test Table Navigation
    await tester.ensureVisible(find.byKey(const Key('dashboard_tables_button')));
    await tester.tap(find.byKey(const Key('dashboard_tables_button')));
    await tester.pumpAndSettle();
    expect(find.byType(TableManagementScreen), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    // 5. Test Booking Navigation
    await tester.ensureVisible(find.byKey(const Key('dashboard_bookings_button')));
    await tester.tap(find.byKey(const Key('dashboard_bookings_button')));
    await tester.pumpAndSettle();
    expect(find.byType(BookingManagementScreen), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    // 6. Test Orders Navigation
    await tester.ensureVisible(find.byKey(const Key('dashboard_orders_button')));
    await tester.tap(find.byKey(const Key('dashboard_orders_button')));
    await tester.pumpAndSettle();
    expect(find.byType(OrderManagementScreen), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    // 7. Test AI Copilot Navigation
    await tester.ensureVisible(find.byKey(const Key('dashboard_ai_copilot_button')));
    await tester.tap(find.byKey(const Key('dashboard_ai_copilot_button')));
    await tester.pumpAndSettle();
    expect(find.byType(AiCopilotScreen), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    // 8. Test Employee Management Navigation
    await tester.ensureVisible(find.byKey(const Key('dashboard_employees_button')));
    await tester.tap(find.byKey(const Key('dashboard_employees_button')));
    await tester.pumpAndSettle();
    expect(find.byType(EmployeeManagementScreen), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    // 9. Test Drawer Navigation for WhatsApp Settings and Payment Reconciliation
    final scaffoldState = tester.state<ScaffoldState>(find.byType(Scaffold));
    scaffoldState.openDrawer();
    await tester.pumpAndSettle();

    expect(find.byKey(const Key('drawer_payment_reconciliation_button')), findsOneWidget);
    expect(find.byKey(const Key('drawer_whatsapp_settings_button')), findsOneWidget);
    expect(find.byKey(const Key('drawer_payment_gateway_button')), findsOneWidget);

    // Tap WhatsApp Settings in Drawer
    await tester.tap(find.byKey(const Key('drawer_whatsapp_settings_button')));
    await tester.pumpAndSettle();
    expect(find.byType(WhatsAppSettingsScreen), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    // Reopen drawer and test Payment Reconciliation
    scaffoldState.openDrawer();
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('drawer_payment_reconciliation_button')));
    await tester.pumpAndSettle();
    expect(find.byType(PaymentReconciliationScreen), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();

    // Reopen drawer and test Payment Gateway Settings
    scaffoldState.openDrawer();
    await tester.pumpAndSettle();
    await tester.tap(find.byKey(const Key('drawer_payment_gateway_button')));
    await tester.pumpAndSettle();
    expect(find.byType(PaymentGatewaySettingsScreen), findsOneWidget);
    await tester.pageBack();
    await tester.pumpAndSettle();
  });
}
