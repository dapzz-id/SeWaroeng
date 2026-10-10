import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/inventory/inventory_service.dart';
import 'package:frontend/core/purchasing/purchasing_models.dart';
import 'package:frontend/core/purchasing/purchasing_service.dart';
import 'package:frontend/presentation/purchasing/purchase_order_list_screen.dart';
import 'package:frontend/presentation/purchasing/receive_goods_screen.dart';
import 'package:frontend/presentation/purchasing/supplier_list_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late MockClient mockClient;
  late ApiClient apiClient;
  late PurchasingService purchasingService;
  late InventoryService inventoryService;

  setUp(() {
    mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/purchasing/suppliers') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'sup-1',
                'tenant_id': 'tenant-1',
                'code': 'SUP-KOPI',
                'name': 'PT Kopi Nusantara',
                'status': 'active',
                'phone': '08123456789',
              },
            ],
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/purchasing/orders') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'po-1',
                'tenant_id': 'tenant-1',
                'supplier_id': 'sup-1',
                'destination_location_id': 'loc-1',
                'reference_number': 'PO-20261004-000001',
                'status': 'approved',
                'supplier': {
                  'id': 'sup-1',
                  'tenant_id': 'tenant-1',
                  'code': 'SUP-KOPI',
                  'name': 'PT Kopi Nusantara',
                  'status': 'active',
                },
                'destination_location': {'id': 'loc-1', 'name': 'Gudang Pusat'},
                'items': [
                  {
                    'id': 'poi-1',
                    'tenant_id': 'tenant-1',
                    'purchase_order_id': 'po-1',
                    'inventory_item_id': 'itm-1',
                    'inventory_item': {
                      'id': 'itm-1',
                      'name': 'Biji Kopi Arabika',
                      'base_unit': 'kg',
                    },
                    'ordered_quantity': '100.0000',
                    'unit_cost_minor': 120000,
                    'received_quantity': '0.0000',
                    'remaining_quantity': '100.0000',
                  },
                ],
              },
            ],
          }),
          200,
        );
      }
      if (request.url.path == '/api/v1/purchasing/receipts' &&
          request.method == 'POST') {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'grn-1',
              'tenant_id': 'tenant-1',
              'purchase_order_id': 'po-1',
              'destination_location_id': 'loc-1',
              'reference_number': 'GRN-20261004-000001',
              'status': 'draft',
              'items': [],
            },
          }),
          201,
        );
      }
      if (request.url.path == '/api/v1/purchasing/receipts/grn-1/post' &&
          request.method == 'POST') {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'grn-1',
              'tenant_id': 'tenant-1',
              'purchase_order_id': 'po-1',
              'destination_location_id': 'loc-1',
              'reference_number': 'GRN-20261004-000001',
              'status': 'posted',
              'items': [],
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
    purchasingService = PurchasingService(apiClient: apiClient);
    inventoryService = InventoryService(apiClient: apiClient);
  });

  testWidgets('SupplierListScreen displays supplier data', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: SupplierListScreen(purchasingService: purchasingService),
      ),
    );

    await tester.pumpAndSettle();

    expect(find.text('Daftar Supplier / Vendor'), findsOneWidget);
    expect(find.text('PT Kopi Nusantara'), findsOneWidget);
    expect(find.text('Kode: SUP-KOPI'), findsOneWidget);
    expect(find.text('Aktif'), findsOneWidget);
  });

  testWidgets('PurchaseOrderListScreen displays PO card with status', (
    tester,
  ) async {
    await tester.pumpWidget(
      MaterialApp(
        home: PurchaseOrderListScreen(
          purchasingService: purchasingService,
          inventoryService: inventoryService,
        ),
      ),
    );

    await tester.pumpAndSettle();

    expect(find.text('Pengadaan & Pembelian (PO)'), findsOneWidget);
    expect(find.text('PO-20261004-000001'), findsOneWidget);
    expect(find.text('Supplier: PT Kopi Nusantara'), findsOneWidget);
    expect(find.text('DISETUJUI'), findsOneWidget);
  });

  testWidgets(
    'ReceiveGoodsScreen displays quantities and validates over-receiving',
    (tester) async {
      final po = PurchaseOrderModel(
        id: 'po-1',
        tenantId: 'tenant-1',
        supplierId: 'sup-1',
        destinationLocationId: 'loc-1',
        referenceNumber: 'PO-20261004-000001',
        status: 'approved',
        destinationLocationName: 'Gudang Pusat',
        items: const [
          PurchaseOrderItemModel(
            id: 'poi-1',
            tenantId: 'tenant-1',
            purchaseOrderId: 'po-1',
            inventoryItemId: 'itm-1',
            inventoryItemName: 'Biji Kopi Arabika',
            baseUnit: 'kg',
            orderedQuantity: 100.0,
            unitCostMinor: 120000,
            receivedQuantity: 0.0,
            remainingQuantity: 100.0,
          ),
        ],
      );

      await tester.pumpWidget(
        MaterialApp(
          home: ReceiveGoodsScreen(
            purchaseOrder: po,
            purchasingService: purchasingService,
          ),
        ),
      );

      await tester.pumpAndSettle();

      // Verify UI shows ordered, received, remaining, unit cost, destination location
      expect(find.text('Terima Barang - PO-20261004-000001'), findsOneWidget);
      expect(
        find.text('Lokasi Tujuan Penerimaan: Gudang Pusat'),
        findsOneWidget,
      );
      expect(find.text('Biji Kopi Arabika'), findsOneWidget);
      expect(find.text('Rp 120.000'), findsOneWidget);
      expect(find.text('Dipesan'), findsOneWidget);
      expect(find.text('Sudah Diterima'), findsOneWidget);
      expect(find.text('Sisa Pesanan'), findsOneWidget);

      // Over-receiving validation: enter 150 (exceeds remaining 100)
      final inputFinder = find.byKey(const Key('received_qty_field_poi-1'));
      expect(inputFinder, findsOneWidget);

      await tester.enterText(inputFinder, '150');
      await tester.pumpAndSettle();

      expect(
        find.text('Tidak boleh melebihi sisa pesanan (100.0)'),
        findsOneWidget,
      );

      // Valid receiving: enter 60
      await tester.enterText(inputFinder, '60');
      await tester.pumpAndSettle();
      expect(
        find.text('Tidak boleh melebihi sisa pesanan (100.0)'),
        findsNothing,
      );

      // Submit button
      final confirmBtn = find.byKey(const Key('confirm_receive_goods_button'));
      await tester.tap(confirmBtn);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));

      expect(
        find.text(
          'Penerimaan GRN-20261004-000001 berhasil dibukukan ke stok inventory!',
        ),
        findsOneWidget,
      );
    },
  );
}
