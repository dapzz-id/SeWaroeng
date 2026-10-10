import 'dart:convert';
import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/database/database.dart';
import 'package:frontend/core/customer/customer_models.dart';
import 'package:frontend/core/pos/pos_models.dart';
import 'package:frontend/core/pos/pos_service.dart';
import 'package:frontend/core/sync/outbox_service.dart';
import 'package:frontend/core/sync/outbox_state.dart';
import 'package:frontend/core/sync/sync_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late AppDatabase db;
  late OutboxService outboxService;

  setUp(() {
    db = AppDatabase(NativeDatabase.memory());
    outboxService = OutboxService(db);
  });

  tearDown(() async {
    await db.close();
  });

  group('SyncService Core Unit Tests', () {
    test('saveCatalogSnapshot caches products and variants into local SQLite', () async {
      final apiClient = ApiClient(
        baseUrl: 'http://example.com',
        httpClient: MockClient((req) async => http.Response('{}', 200)),
      );
      final syncService = SyncService(
        db: db,
        apiClient: apiClient,
        outboxService: outboxService,
      );

      final category = PosCatalogCategory(
        id: 'cat-01',
        name: 'Minuman',
        slug: 'minuman',
      );
      final variant = PosCatalogVariant(
        id: 'var-01',
        name: 'Large',
        sku: 'ES-TEH-L',
        resolvedPrice: const PosCatalogPrice(
          priceId: 'prc-01',
          amount: 8000,
          currency: 'IDR',
        ),
      );
      final product = PosCatalogProduct(
        id: 'prod-01',
        name: 'Es Teh Manis',
        description: 'Segar dan manis',
        categoryId: 'cat-01',
        categoryName: 'Minuman',
        variants: [variant],
        modifierGroups: const [],
      );

      await syncService.saveCatalogSnapshot(
        tenantId: 'tenant-01',
        outletId: 'outlet-01',
        products: [product],
        categories: [category],
      );

      final cachedProducts = await syncService.getOfflineCatalog('outlet-01');
      expect(cachedProducts.length, equals(1));
      expect(cachedProducts.first.name, equals('Es Teh Manis'));
      expect(cachedProducts.first.variants.length, equals(1));
      expect(cachedProducts.first.variants.first.resolvedPrice?.amount, equals(8000));
    });

    test('recordOfflineSale atomically stores local sale, items, and transactional outbox', () async {
      final apiClient = ApiClient(
        baseUrl: 'http://example.com',
        httpClient: MockClient((req) async => http.Response('{}', 200)),
      );
      final syncService = SyncService(
        db: db,
        apiClient: apiClient,
        outboxService: outboxService,
      );

      final variant = PosCatalogVariant(
        id: 'var-01',
        name: 'Regular',
        sku: 'KOPI-REG',
        resolvedPrice: const PosCatalogPrice(
          priceId: 'prc-01',
          amount: 15000,
          currency: 'IDR',
        ),
      );
      final product = PosCatalogProduct(
        id: 'prod-01',
        name: 'Kopi Susu',
        categoryId: 'cat-01',
        categoryName: 'Kopi',
        variants: [variant],
        modifierGroups: const [],
      );

      final cartItem = PosCartItem(
        id: 'cart-item-1',
        product: product,
        variant: variant,
        selectedModifiers: const [],
        quantity: 2,
        notes: 'Sedikit es',
      );

      final sale = await syncService.recordOfflineSale(
        tenantId: 'tenant-01',
        outletId: 'outlet-01',
        shiftId: 'shift-01',
        cashierUserId: 'user-01',
        customer: null,
        items: [cartItem],
        subtotalMinor: 30000,
        discountMinor: 0,
        taxMinor: 0,
        totalMinor: 30000,
        paymentMethod: 'cash',
        cashReceivedMinor: 50000,
        cashChangeMinor: 20000,
        notes: 'Meja 4',
      );

      expect(sale.id, isNotEmpty);
      expect(sale.status, equals('finalized'));
      expect(sale.totalMinor, equals(30000));
      expect(sale.syncStatus, equals(OfflineSyncStatus.syncPending));

      // 1. Verify LocalSale exists in database
      final localSales = await (db.select(db.localSalesTable)..where((t) => t.id.equals(sale.id))).get();
      expect(localSales.length, equals(1));
      expect(localSales.first.totalMinor, equals(30000));
      expect(localSales.first.syncStatus, equals('pending'));

      // 2. Verify LocalSaleItems exists
      final localItems = await (db.select(db.localSaleItemsTable)..where((t) => t.saleId.equals(sale.id))).get();
      expect(localItems.length, equals(1));
      expect(localItems.first.quantity, equals(2.0));
      expect(localItems.first.unitPriceMinor, equals(15000));

      // 3. Verify Outbox exists with separate operation ID and idempotency key
      final pendingCommands = await outboxService.fetchPendingCommands();
      expect(pendingCommands.length, equals(1));
      final cmd = pendingCommands.first;
      expect(cmd.aggregateId, equals(sale.id));
      expect(cmd.id, isNot(equals(sale.id))); // Entity ID != Operation ID
      expect(cmd.commandType, equals('sales.finalize'));

      final payload = jsonDecode(cmd.payload) as Map<String, dynamic>;
      expect(payload['sale_id'], equals(sale.id));
      expect(payload['idempotency_key'], isNotNull);
      expect(payload['idempotency_key'], isNot(equals(sale.id))); // Entity ID != Idempotency Key
      expect(payload['payment']['method'], equals('cash'));
      expect(payload['payment']['cash_received_minor'], equals(50000));
    });

    test('syncPendingOperations uploads batch and updates local sale to synced on success', () async {
      late Map<String, dynamic> sentBody;
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/sync' && request.method == 'POST') {
          sentBody = jsonDecode(request.body) as Map<String, dynamic>;
          final ops = sentBody['operations'] as List<dynamic>;
          final op = ops.first as Map<String, dynamic>;

          return http.Response(
            jsonEncode({
              'success': true,
              'message': 'Sync operations processed.',
              'data': {
                'client_id': sentBody['client_id'],
                'device_id': sentBody['device_id'],
                'checkpoint': 'chk-001',
                'results': [
                  {
                    'operation_id': op['id'],
                    'aggregate_type': 'sale',
                    'aggregate_id': op['aggregate_id'],
                    'status': 'accepted',
                    'code': 'SALE_SYNCED',
                    'message': 'Sale finalized and synced successfully',
                    'server_timestamp': DateTime.now().toIso8601String(),
                  }
                ],
              },
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://example.com', httpClient: mockClient);
      final syncService = SyncService(
        db: db,
        apiClient: apiClient,
        outboxService: outboxService,
      );

      final variant = PosCatalogVariant(
        id: 'var-01',
        name: 'Regular',
        sku: 'KOPI-01',
        resolvedPrice: const PosCatalogPrice(priceId: 'p1', amount: 10000, currency: 'IDR'),
      );
      final product = PosCatalogProduct(
        id: 'prod-01',
        name: 'Kopi',
        categoryId: 'c1',
        categoryName: 'Minuman',
        variants: [variant],
        modifierGroups: const [],
      );

      final sale = await syncService.recordOfflineSale(
        tenantId: 'tenant-01',
        outletId: 'outlet-01',
        shiftId: 'shift-01',
        cashierUserId: 'user-01',
        customer: null,
        items: [
          PosCartItem(id: 'c-1', product: product, variant: variant, selectedModifiers: const [], quantity: 1),
        ],
        subtotalMinor: 10000,
        discountMinor: 0,
        taxMinor: 0,
        totalMinor: 10000,
        paymentMethod: 'cash',
        cashReceivedMinor: 10000,
        cashChangeMinor: 0,
      );

      final syncedCount = await syncService.syncPendingOperations();
      expect(syncedCount, equals(1));

      // Outbox should now be empty of pending commands
      final pendingAfter = await outboxService.fetchPendingCommands();
      expect(pendingAfter, isEmpty);

      // Local sale must be updated to synced
      final localSales = await syncService.getLocalSales('outlet-01');
      expect(localSales.length, equals(1));
      expect(localSales.first.id, equals(sale.id));
      expect(localSales.first.syncStatus, equals(OfflineSyncStatus.synced));
      expect(localSales.first.syncedAt, isNotNull);
    });

    test('syncPendingOperations marks local sale as needsReview and does NOT delete on conflict/blocked', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/sync') {
          final sentBody = jsonDecode(request.body) as Map<String, dynamic>;
          final ops = sentBody['operations'] as List<dynamic>;
          final op = ops.first as Map<String, dynamic>;

          return http.Response(
            jsonEncode({
              'success': true,
              'message': 'Sync operations processed.',
              'data': {
                'client_id': sentBody['client_id'],
                'device_id': sentBody['device_id'],
                'checkpoint': 'chk-002',
                'results': [
                  {
                    'operation_id': op['id'],
                    'aggregate_type': 'sale',
                    'aggregate_id': op['aggregate_id'],
                    'status': 'blocked',
                    'code': 'INSUFFICIENT_STOCK',
                    'message': 'Stok tidak mencukupi di inventory location',
                    'server_timestamp': DateTime.now().toIso8601String(),
                  }
                ],
              },
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://example.com', httpClient: mockClient);
      final syncService = SyncService(
        db: db,
        apiClient: apiClient,
        outboxService: outboxService,
      );

      final variant = PosCatalogVariant(
        id: 'var-01',
        name: 'Regular',
        sku: 'KOPI-01',
        resolvedPrice: const PosCatalogPrice(priceId: 'p1', amount: 10000, currency: 'IDR'),
      );
      final product = PosCatalogProduct(
        id: 'prod-01',
        name: 'Kopi',
        categoryId: 'c1',
        categoryName: 'Minuman',
        variants: [variant],
        modifierGroups: const [],
      );

      final sale = await syncService.recordOfflineSale(
        tenantId: 'tenant-01',
        outletId: 'outlet-01',
        shiftId: 'shift-01',
        cashierUserId: 'user-01',
        customer: null,
        items: [
          PosCartItem(id: 'c-2', product: product, variant: variant, selectedModifiers: const [], quantity: 5),
        ],
        subtotalMinor: 50000,
        discountMinor: 0,
        taxMinor: 0,
        totalMinor: 50000,
        paymentMethod: 'cash',
        cashReceivedMinor: 50000,
        cashChangeMinor: 0,
      );

      final syncedCount = await syncService.syncPendingOperations();
      expect(syncedCount, equals(0));

      // Local sale is NEVER deleted, but marked as needsReview with error message
      final localSales = await syncService.getLocalSales('outlet-01');
      expect(localSales.length, equals(1));
      expect(localSales.first.id, equals(sale.id));
      expect(localSales.first.syncStatus, equals(OfflineSyncStatus.needsReview));
      expect(localSales.first.syncError, contains('INSUFFICIENT_STOCK'));

      // Outbox status transitioned to requires_action
      final outboxItem = await (db.select(db.syncOutboxTable)..where((t) => t.aggregateId.equals(sale.id))).getSingle();
      expect(outboxItem.state, equals(OutboxState.requiresAction.toValue()));
    });

    test('network failure (500 or timeout) retains local sale and schedules retry backoff', () async {
      final mockClient = MockClient((request) async {
        return http.Response('Internal Server Error', 500);
      });

      final apiClient = ApiClient(baseUrl: 'http://example.com', httpClient: mockClient);
      final syncService = SyncService(
        db: db,
        apiClient: apiClient,
        outboxService: outboxService,
      );

      final variant = PosCatalogVariant(
        id: 'var-01',
        name: 'Regular',
        sku: 'KOPI-01',
        resolvedPrice: const PosCatalogPrice(priceId: 'p1', amount: 10000, currency: 'IDR'),
      );
      final product = PosCatalogProduct(
        id: 'prod-01',
        name: 'Kopi',
        categoryId: 'c1',
        categoryName: 'Minuman',
        variants: [variant],
        modifierGroups: const [],
      );

      final sale = await syncService.recordOfflineSale(
        tenantId: 'tenant-01',
        outletId: 'outlet-01',
        shiftId: 'shift-01',
        cashierUserId: 'user-01',
        customer: null,
        items: [
          PosCartItem(id: 'c-3', product: product, variant: variant, selectedModifiers: const [], quantity: 1),
        ],
        subtotalMinor: 10000,
        discountMinor: 0,
        taxMinor: 0,
        totalMinor: 10000,
        paymentMethod: 'cash',
        cashReceivedMinor: 10000,
        cashChangeMinor: 0,
      );

      final syncedCount = await syncService.syncPendingOperations();
      expect(syncedCount, equals(0));

      // Local sale still exists with localSaved status
      final localSales = await syncService.getLocalSales('outlet-01');
      expect(localSales.length, equals(1));
      expect(localSales.first.id, equals(sale.id));

      // Outbox item attemptCount is incremented, nextAttemptAt set
      final outboxItem = await (db.select(db.syncOutboxTable)..where((t) => t.aggregateId.equals(sale.id))).getSingle();
      expect(outboxItem.attemptCount, equals(1));
      expect(outboxItem.nextAttemptAt, isNotNull);
    });
  });

  group('POS Service Offline Integration & Payment Restrictions', () {
    test('offline mode restricts online payment gateways (Midtrans, Duitku, QRIS)', () async {
      final apiClient = ApiClient(
        baseUrl: 'http://example.com',
        httpClient: MockClient((req) async => http.Response('{}', 200)),
      );
      final syncService = SyncService(
        db: db,
        apiClient: apiClient,
        outboxService: outboxService,
      );
      final posService = PosService(
        apiClient: apiClient,
        syncService: syncService,
      );

      posService.setOfflineMode(true);
      expect(posService.isOffline, isTrue);

      final variant = PosCatalogVariant(
        id: 'var-01',
        name: 'Regular',
        sku: 'KOPI-01',
        resolvedPrice: const PosCatalogPrice(priceId: 'p1', amount: 10000, currency: 'IDR'),
      );
      final product = PosCatalogProduct(
        id: 'prod-01',
        name: 'Kopi',
        categoryId: 'c1',
        categoryName: 'Minuman',
        variants: [variant],
        modifierGroups: const [],
      );

      posService.addToCart(product: product, variant: variant);

      // Attempting Midtrans offline must be blocked
      final midtransSale = await posService.finalizeOfflineSale(
        tenantId: 'tenant-01',
        outletId: 'outlet-01',
        shiftId: 'shift-01',
        cashierUserId: 'user-01',
        paymentMethod: 'midtrans',
        cashReceivedMinor: null,
      );
      expect(midtransSale, isNull);
      expect(posService.errorMessage, contains('Metode pembayaran online gateway tidak tersedia'));

      // Attempting Duitku offline must be blocked
      final duitkuSale = await posService.finalizeOfflineSale(
        tenantId: 'tenant-01',
        outletId: 'outlet-01',
        shiftId: 'shift-01',
        cashierUserId: 'user-01',
        paymentMethod: 'duitku',
        cashReceivedMinor: null,
      );
      expect(duitkuSale, isNull);
      expect(posService.errorMessage, contains('Metode pembayaran online gateway tidak tersedia'));

      // Attempting QRIS offline must be blocked
      final qrisSale = await posService.finalizeOfflineSale(
        tenantId: 'tenant-01',
        outletId: 'outlet-01',
        shiftId: 'shift-01',
        cashierUserId: 'user-01',
        paymentMethod: 'qris',
        cashReceivedMinor: null,
      );
      expect(qrisSale, isNull);
      expect(posService.errorMessage, contains('Metode pembayaran online gateway tidak tersedia'));

      // Finalizing cash sale offline succeeds and clears cart
      final offlineSale = await posService.finalizeOfflineSale(
        tenantId: 'tenant-01',
        outletId: 'outlet-01',
        shiftId: 'shift-01',
        cashierUserId: 'user-01',
        paymentMethod: 'cash',
        cashReceivedMinor: 10000,
      );

      expect(offlineSale, isNotNull);
      expect(offlineSale!.id, isNotEmpty);
      expect(offlineSale.totalMinor, equals(10000));
      expect(posService.cartItems, isEmpty);
    });

    test('Local SQLite strict multi-tenant isolation: Tenant A records are invisible to Tenant B', () async {
      int requestCountTenantA = 0;
      int requestCountTenantB = 0;

      final apiClient = ApiClient(
        baseUrl: 'http://example.com',
        httpClient: MockClient((req) async {
          final body = jsonDecode(req.body) as Map<String, dynamic>;
          final ops = body['operations'] as List<dynamic>;
          for (final op in ops) {
            final payload = op['payload'] as Map<String, dynamic>;
            if (payload['tenant_id'] == 'tenant-A') requestCountTenantA++;
            if (payload['tenant_id'] == 'tenant-B') requestCountTenantB++;
          }
          return http.Response(
            jsonEncode({
              'data': {
                'results': ops.map((op) => {
                  'operation_id': op['id'],
                  'aggregate_id': op['aggregate_id'],
                  'status': 'accepted',
                  'code': 'OK',
                }).toList(),
              }
            }),
            200,
          );
        }),
      );

      final syncService = SyncService(
        db: db,
        apiClient: apiClient,
        outboxService: outboxService,
      );

      // 1. Cache Tenant A Catalog
      final variantA = PosCatalogVariant(
        id: 'var-A1',
        name: 'Reguler',
        sku: 'A1',
        resolvedPrice: const PosCatalogPrice(
          priceId: 'prc-A1',
          amount: 15000,
          currency: 'IDR',
        ),
      );
      final productA = PosCatalogProduct(
        id: 'prod-A1',
        name: 'Kopi Tenant A',
        description: null,
        categoryId: null,
        categoryName: null,
        variants: [variantA],
        modifierGroups: const [],
      );

      await syncService.saveCatalogSnapshot(
        tenantId: 'tenant-A',
        outletId: 'outlet-A1',
        products: [productA],
        categories: [],
      );

      // 2. Cache Tenant A Customers
      await syncService.saveCustomerSnapshot(
        'tenant-A',
        [
          CustomerModel(
            id: 'cust-A1',
            tenantId: 'tenant-A',
            name: 'Pelanggan Warung A',
            phone: '0812345678',
            email: null,
            notes: null,
            isActive: true,
            segment: 'regular',
            marketingOptIn: false,
            createdAt: DateTime.now(),
            updatedAt: DateTime.now(),
          ),
        ],
      );

      // 3. Record Tenant A Offline Sale
      final cartItemA = PosCartItem(
        id: 'item-A1',
        product: productA,
        variant: variantA,
        selectedModifiers: const [],
        quantity: 1,
      );

      await syncService.recordOfflineSale(
        tenantId: 'tenant-A',
        outletId: 'outlet-A1',
        shiftId: 'shift-A1',
        cashierUserId: 'user-01',
        customer: null,
        items: [cartItemA],
        subtotalMinor: 15000,
        discountMinor: 0,
        taxMinor: 0,
        totalMinor: 15000,
        paymentMethod: 'cash',
        cashReceivedMinor: 20000,
        cashChangeMinor: 5000,
      );

      // 4. Verify Tenant B context sees NOTHING from Tenant A
      final catalogB = await syncService.getOfflineCatalog('outlet-B1', tenantId: 'tenant-B');
      expect(catalogB, isEmpty);

      final customersB = await syncService.getOfflineCustomers('tenant-B');
      expect(customersB, isEmpty);

      final salesB = await syncService.getLocalSales('outlet-B1', tenantId: 'tenant-B');
      expect(salesB, isEmpty);

      // 5. Tenant B sync MUST NOT send Tenant A's outbox command
      final syncedB = await syncService.syncPendingOperations(tenantId: 'tenant-B');
      expect(syncedB, equals(0));
      expect(requestCountTenantA, equals(0));
      expect(requestCountTenantB, equals(0));

      // 6. Switch back to Tenant A context: All Tenant A data remains visible and intact
      final catalogA = await syncService.getOfflineCatalog('outlet-A1', tenantId: 'tenant-A');
      expect(catalogA.length, equals(1));
      expect(catalogA.first.name, equals('Kopi Tenant A'));

      final customersA = await syncService.getOfflineCustomers('tenant-A');
      expect(customersA.length, equals(1));
      expect(customersA.first.name, equals('Pelanggan Warung A'));

      final salesA = await syncService.getLocalSales('outlet-A1', tenantId: 'tenant-A');
      expect(salesA.length, equals(1));
      expect(salesA.first.tenantId, equals('tenant-A'));

      // 7. Tenant A sync successfully uploads Tenant A command
      final syncedA = await syncService.syncPendingOperations(tenantId: 'tenant-A');
      expect(syncedA, equals(1));
      expect(requestCountTenantA, equals(1));
    });
  });
}
