import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/inventory/inventory_models.dart';
import 'package:frontend/core/inventory/inventory_service.dart';
import 'package:frontend/presentation/inventory/inventory_dashboard_screen.dart';
import 'package:frontend/presentation/inventory/inventory_movement_history_screen.dart';
import 'package:frontend/presentation/inventory/stock_adjustment_dialog.dart';
import 'package:frontend/presentation/inventory/stock_opname_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late MockClient mockClient;
  late ApiClient apiClient;
  late InventoryService inventoryService;

  setUp(() {
    mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/inventory/locations') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'loc-1',
                'name': 'Gudang Pusat',
                'code': 'GDG-01',
                'type': 'warehouse',
                'is_active': true,
              },
            ],
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/inventory/items') {
        return http.Response(
          jsonEncode({
            'data': {
              'items': [
                {
                  'id': 'item-1',
                  'name': 'Biji Kopi Arabika',
                  'sku': 'BKA-01',
                  'base_unit': 'kg',
                  'item_type': 'sellable',
                  'status': 'active',
                  'total_on_hand': 15.5,
                  'total_reserved': 1.0,
                  'total_available': 14.5,
                  'location_balance': {
                    'location_id': 'loc-1',
                    'on_hand': 15.5,
                    'reserved': 1.0,
                    'available': 14.5,
                    'reorder_level': 10.0,
                    'is_low_stock': false,
                  },
                },
              ],
              'pagination': {'total': 1, 'current_page': 1, 'last_page': 1},
            },
          }),
          200,
        );
      }
      if (request.url.path.contains('/movements')) {
        return http.Response(
          jsonEncode({
            'data': {
              'items': [
                {
                  'id': 'mov-1',
                  'movement_number': 'INV-20261004-0001',
                  'location_id': 'loc-1',
                  'location_name': 'Gudang Pusat',
                  'movement_type': 'opening_balance',
                  'quantity_delta': '15.5000',
                  'unit': 'kg',
                  'before_on_hand': '0.0000',
                  'after_on_hand': '15.5000',
                  'reason': 'Opening Balance',
                  'note': 'Initial audit',
                  'occurred_at': '2026-10-04T07:00:00Z',
                },
              ],
              'pagination': {'total': 1, 'current_page': 1, 'last_page': 1},
            },
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/inventory/opnames') {
        return http.Response(
          jsonEncode({
            'data': {
              'items': [
                {
                  'id': 'op-1',
                  'opname_number': 'OPN-20261004-001',
                  'location_id': 'loc-1',
                  'location_name': 'Gudang Pusat',
                  'status': 'draft',
                  'notes': 'Monthly audit',
                  'created_at': '2026-10-04T07:00:00Z',
                },
              ],
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
    );
    inventoryService = InventoryService(apiClient: apiClient);
  });

  testWidgets('renders inventory dashboard with metrics and item list', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: InventoryDashboardScreen(inventoryService: inventoryService),
      ),
    );

    // Initial pump
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Manajemen Inventory & Stok'), findsOneWidget);
    expect(find.text('Total Barang'), findsOneWidget);
    expect(find.text('Stok Rendah'), findsWidgets);
    expect(find.text('Stok Habis'), findsOneWidget);
    expect(find.text('Penyesuaian Stok'), findsOneWidget);
    expect(find.text('Stock Opname'), findsOneWidget);
    expect(find.text('Biji Kopi Arabika'), findsOneWidget);
    expect(find.textContaining('15.50 kg'), findsOneWidget);
  });

  testWidgets('stock adjustment dialog validates required delta', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1024, 1024);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    await inventoryService.fetchLocations();
    await inventoryService.fetchItems();

    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: StockAdjustmentDialog(
            inventoryService: inventoryService,
            initialItem: inventoryService.items.firstOrNull,
            initialLocation: inventoryService.locations.firstOrNull,
          ),
        ),
      ),
    );

    await tester.pumpAndSettle();

    expect(find.text('Penyesuaian Stok'), findsOneWidget);
    expect(find.text('Konfirmasi Penyesuaian'), findsOneWidget);

    // Scroll into view if needed and press confirm without delta
    await tester.ensureVisible(find.text('Konfirmasi Penyesuaian'));
    await tester.tap(find.text('Konfirmasi Penyesuaian'));
    await tester.pumpAndSettle();

    expect(find.text('Masukkan jumlah perubahan'), findsOneWidget);
  });

  testWidgets('renders stock opname screen with audit items', (tester) async {
    await tester.pumpWidget(
      MaterialApp(home: StockOpnameScreen(inventoryService: inventoryService)),
    );

    await tester.pumpAndSettle();

    expect(find.text('Stock Opname (Audit Fisik)'), findsOneWidget);
    expect(find.text('OPN-20261004-001'), findsOneWidget);
    expect(find.text('DRAFT / MENUNGGU'), findsOneWidget);
    expect(find.text('Review & Setujui Opname'), findsOneWidget);
  });

  testWidgets('renders movement history timeline screen', (tester) async {
    final item = InventoryItemModel(
      id: 'item-1',
      tenantId: 'tenant-1',
      name: 'Biji Kopi Arabika',
      baseUnit: 'kg',
      status: 'active',
    );

    await tester.pumpWidget(
      MaterialApp(
        home: InventoryMovementHistoryScreen(
          inventoryService: inventoryService,
          item: item,
        ),
      ),
    );

    await tester.pumpAndSettle();

    expect(find.text('Riwayat Mutasi: Biji Kopi Arabika'), findsOneWidget);
    expect(find.text('INV-20261004-0001'), findsOneWidget);
    expect(find.text('Saldo Awal'), findsOneWidget);
    expect(find.text('+15.50 kg'), findsOneWidget);
  });
}
