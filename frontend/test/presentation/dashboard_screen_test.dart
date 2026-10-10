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
import 'package:frontend/presentation/dashboard/dashboard_placeholder_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late Directory tempDir;
  late DeviceService deviceService;

  setUp(() async {
    tempDir = await Directory.systemTemp.createTemp('dash_widget_test_');
    deviceService = DeviceService(tempDir);
  });

  tearDown(() async {
    try {
      if (await tempDir.exists()) {
        await tempDir.delete(recursive: true);
      }
    } catch (_) {}
  });

  testWidgets('renders Dashboard screen with KPIs, alerts, and filters', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 2400);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/dashboard') {
        return http.Response(
          jsonEncode({
            'data': {
              'period': {
                'name': 'today',
                'start_date': '2026-10-06T00:00:00+07:00',
                'end_date': '2026-10-06T23:59:59+07:00',
                'timezone': 'Asia/Jakarta',
              },
              'kpi': {
                'gross_sales_minor': 10000000,
                'refunds_minor': 0,
                'net_sales_minor': 10000000,
                'transaction_count': 50,
                'average_order_value_minor': 200000,
                'gross_profit_minor': 4000000,
                'cogs_minor': 6000000,
                'net_cashflow_minor': 3000000,
              },
              'top_products': [
                {
                  'product_name': 'Kopi Gula Aren',
                  'units_sold': 30,
                  'revenue_minor': 600000,
                },
              ],
              'low_stock': [
                {
                  'item_id': 'item-1',
                  'item_name': 'Susu UHT',
                  'sku': 'SSU-001',
                  'available': 2.0,
                  'reorder_level': 10.0,
                  'locationName': 'Dapur',
                  'outlet_id': 'out-1',
                },
              ],
              'top_customers': [
                {
                  'customer_id': 'cust-1',
                  'customer_name': 'Siti Rahma',
                  'transaction_count': 3,
                  'total_spend_minor': 150000,
                },
              ],
              'payment_summary': {
                'succeeded': 50,
                'processing': 0,
                'pending': 0,
                'failed': 0,
                'refunded': 0,
                'partially_refunded': 0,
              },
              'outlet_summary': [
                {
                  'outlet_id': 'out-1',
                  'outlet_name': 'Outlet Utama',
                  'transaction_count': 50,
                  'gross_sales_minor': 10000000,
                  'aov_minor': 200000,
                },
              ],
              'alerts': {
                'unread_count': 1,
                'has_critical': false,
              },
            },
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/notifications/unread-count') {
        return http.Response(
          jsonEncode({
            'data': {'unread_count': 1},
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
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
      name: 'Waroeng Kopi Berkah',
      slug: 'waroeng-kopi',
      businessType: 'fnb',
      status: 'active',
      role: 'owner',
      phone: '081234567890',
      email: 'owner@berkah.com',
      city: 'Jakarta Selatan',
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

    // Verify Business header
    expect(find.text('Waroeng Kopi Berkah'), findsOneWidget);
    expect(find.byKey(const Key('dashboard_notification_bell_button')), findsOneWidget);

    // Verify filter chips
    expect(find.text('Hari Ini'), findsOneWidget);
    expect(find.text('Kemarin'), findsOneWidget);
    expect(find.text('7 Hari'), findsOneWidget);
    expect(find.text('30 Hari'), findsOneWidget);

    // Verify KPIs
    expect(find.text('Penjualan Bersih'), findsOneWidget);
    expect(find.text('Total Transaksi'), findsOneWidget);
    expect(find.text('Rata-rata Order (AOV)'), findsOneWidget);
    expect(find.text('Laba Kotor'), findsOneWidget);
    expect(find.text('Arus Kas Bersih'), findsOneWidget);

    // Verify cards
    expect(find.text('Produk Terlaris'), findsOneWidget);
    expect(find.text('Kopi Gula Aren'), findsOneWidget);
    expect(find.text('Stok Menipis / Perlu Reorder'), findsOneWidget);
    expect(find.text('Susu UHT'), findsOneWidget);
    expect(find.text('Pelanggan Teratas'), findsOneWidget);
    expect(find.text('Siti Rahma'), findsOneWidget);
    expect(find.text('Status Pembayaran'), findsOneWidget);
  });
}
