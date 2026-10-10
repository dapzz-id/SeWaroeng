import 'dart:convert';
import 'package:drift/native.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/database/database.dart';
import 'package:frontend/core/pos/pos_models.dart';
import 'package:frontend/core/pos/pos_service.dart';
import 'package:frontend/core/sync/outbox_service.dart';
import 'package:frontend/core/sync/sync_service.dart';
import 'package:frontend/presentation/pos/pos_screen.dart';
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

  testWidgets('E2E Offline POS + Sync Flow: Multiple sales offline, reconnection, and sync', (tester) async {
    // 1. Setup Mock Client for Online and Sync operations
    final syncedOperationIds = <String>{};
    var syncCallCount = 0;

    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/pos/catalog' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'categories': [
                {'id': 'cat-1', 'name': 'Makanan', 'slug': 'makanan'},
              ],
              'products': [
                {
                  'id': 'prod-1',
                  'name': 'Nasi Goreng Spesial',
                  'category_id': 'cat-1',
                  'category_name': 'Makanan',
                  'variants': [
                    {
                      'id': 'var-1',
                      'name': 'Porsi Biasa',
                      'sku': 'NASGOR-01',
                      'resolved_price': {'price_id': 'prc-1', 'amount': 25000, 'currency': 'IDR'},
                    },
                  ],
                  'modifier_groups': [],
                },
                {
                  'id': 'prod-2',
                  'name': 'Es Jeruk',
                  'category_id': 'cat-1',
                  'category_name': 'Minuman',
                  'variants': [
                    {
                      'id': 'var-2',
                      'name': 'Gelas Besar',
                      'sku': 'ESJR-01',
                      'resolved_price': {'price_id': 'prc-2', 'amount': 10000, 'currency': 'IDR'},
                    },
                  ],
                  'modifier_groups': [],
                },
              ],
            },
          }),
          200,
        );
      }

      if (request.url.path == '/api/v1/sync' && request.method == 'POST') {
        syncCallCount++;
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        final operations = body['operations'] as List<dynamic>;
        final results = <Map<String, dynamic>>[];

        for (final op in operations) {
          final opMap = op as Map<String, dynamic>;
          final opId = opMap['id'] as String;
          final saleId = opMap['aggregate_id'] as String;

          final isAlreadyProcessed = syncedOperationIds.contains(opId);
          syncedOperationIds.add(opId);

          results.add({
            'operation_id': opId,
            'aggregate_type': 'sale',
            'aggregate_id': saleId,
            'status': isAlreadyProcessed ? 'already_processed' : 'accepted',
            'code': isAlreadyProcessed ? 'ALREADY_SYNCED' : 'SALE_SYNCED',
            'message': isAlreadyProcessed ? 'Operation was already synced' : 'Sale successfully finalized',
            'server_timestamp': DateTime.now().toIso8601String(),
          });
        }

        return http.Response(
          jsonEncode({
            'success': true,
            'message': 'Sync operations processed.',
            'data': {
              'client_id': body['client_id'],
              'device_id': body['device_id'],
              'checkpoint': 'chk-$syncCallCount',
              'results': results,
            },
          }),
          200,
        );
      }

      if (request.url.path == '/api/v1/pos/sales' && request.method == 'GET') {
        return http.Response(jsonEncode({'data': []}), 200);
      }

      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://example.com', httpClient: mockClient);
    final syncService = SyncService(
      db: db,
      apiClient: apiClient,
      outboxService: outboxService,
    );
    final posService = PosService(
      apiClient: apiClient,
      syncService: syncService,
    );

    // 2. Online: Fetch and cache catalog snapshot
    await posService.fetchCatalog(outletId: 'outlet-01');
    expect(posService.products.length, equals(2));

    // Verify snapshots were saved to SQLite
    final cached = await syncService.getOfflineCatalog('outlet-01');
    expect(cached.length, equals(2));

    // 3. Disconnect Network: Switch to Offline Mode
    posService.setOfflineMode(true);
    expect(posService.isOffline, isTrue);

    // 4. Create Sale 1 Offline (Nasi Goreng Spesial x2 = 50,000)
    final nasgor = posService.products.firstWhere((p) => p.name == 'Nasi Goreng Spesial');
    posService.addToCart(product: nasgor, variant: nasgor.variants.first, quantity: 2);
    expect(posService.cartSubtotalMinor, equals(50000));

    final sale1 = await posService.finalizeOfflineSale(
      tenantId: 'tenant-01',
      outletId: 'outlet-01',
      shiftId: 'shift-01',
      cashierUserId: 'user-01',
      paymentMethod: 'cash',
      cashReceivedMinor: 50000,
    );

    expect(sale1, isNotNull);
    expect(sale1!.id, isNotEmpty);
    expect(sale1.totalMinor, equals(50000));
    expect(posService.cartItems, isEmpty);

    // 5. Create Sale 2 Offline (Es Jeruk x3 = 30,000)
    final esJeruk = posService.products.firstWhere((p) => p.name == 'Es Jeruk');
    posService.addToCart(product: esJeruk, variant: esJeruk.variants.first, quantity: 3);
    expect(posService.cartSubtotalMinor, equals(30000));

    final sale2 = await posService.finalizeOfflineSale(
      tenantId: 'tenant-01',
      outletId: 'outlet-01',
      shiftId: 'shift-01',
      cashierUserId: 'user-01',
      paymentMethod: 'cash',
      cashReceivedMinor: 50000,
    );

    expect(sale2, isNotNull);
    expect(sale2!.id, isNotEmpty);
    expect(sale2.totalMinor, equals(30000));

    // 6. Verify Local SQLite Persistence & Outbox Operations
    final localSalesBefore = await syncService.getLocalSales('outlet-01');
    expect(localSalesBefore.length, equals(2));
    expect(localSalesBefore.every((s) => s.syncStatus == OfflineSyncStatus.syncPending), isTrue);

    final pendingCommands = await outboxService.fetchPendingCommands();
    expect(pendingCommands.length, equals(2));

    // 7. Render POS Screen in Offline State
    await tester.pumpWidget(
      MaterialApp(
        home: PosScreen(
          outletId: 'outlet-01',
          outletName: 'Waroeng Pusat',
          activeShiftId: 'shift-01',
          cashierUserId: 'user-01',
          posService: posService,
        ),
      ),
    );
    await tester.pumpAndSettle();

    // Verify OFFLINE badge is displayed in AppBar
    expect(find.text('OFFLINE'), findsOneWidget);

    // 8. Reconnect Network & Trigger Synchronization
    posService.setOfflineMode(false);
    expect(posService.isOffline, isFalse);

    final syncedCount = await syncService.syncPendingOperations();
    expect(syncedCount, equals(2));
    expect(syncCallCount, equals(1));

    // Verify both sales are now marked as synced
    final localSalesAfter = await syncService.getLocalSales('outlet-01');
    expect(localSalesAfter.length, equals(2));
    expect(localSalesAfter.every((s) => s.syncStatus == OfflineSyncStatus.synced), isTrue);

    // Verify outbox pending commands is now 0
    final pendingAfter = await outboxService.fetchPendingCommands();
    expect(pendingAfter, isEmpty);

    // 9. Replay / Retry Synchronization (Idempotency test)
    final replaySyncedCount = await syncService.syncPendingOperations();
    expect(replaySyncedCount, equals(0)); // Nothing pending to sync
  });
}
