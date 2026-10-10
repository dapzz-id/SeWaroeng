import 'dart:convert';
import 'package:flutter/foundation.dart';
import 'package:drift/drift.dart';
import 'package:uuid/uuid.dart';

import '../api/api_client.dart';
import '../customer/customer_models.dart';
import '../database/database.dart';
import '../pos/pos_models.dart';
import 'outbox_service.dart';
import 'outbox_state.dart';

class SyncService extends ChangeNotifier {
  static const _uuid = Uuid();

  final AppDatabase db;
  final ApiClient apiClient;
  final OutboxService outboxService;

  AppDatabase get _db => db;
  ApiClient get _apiClient => apiClient;
  OutboxService get _outboxService => outboxService;

  bool _isSyncing = false;
  String? _lastSyncError;
  DateTime? _lastSyncTime;

  bool get isSyncing => _isSyncing;
  String? get lastSyncError => _lastSyncError;
  DateTime? get lastSyncTime => _lastSyncTime;

  SyncService({
    required this.db,
    required this.apiClient,
    required this.outboxService,
  });

  // -------------------------------------------------------------
  // CATALOG & CUSTOMER SNAPSHOTS
  // -------------------------------------------------------------

  /// Caches catalog and price snapshot locally for offline POS usage.
  Future<void> saveCatalogSnapshot({
    required String tenantId,
    required String outletId,
    required List<PosCatalogProduct> products,
    required List<PosCatalogCategory> categories,
  }) async {
    await _db.transaction(() async {
      for (final p in products) {
        await _db.into(_db.localProductSnapshotsTable).insertOnConflictUpdate(
              LocalProductSnapshotsTableCompanion.insert(
                id: p.id,
                tenantId: tenantId,
                categoryId: Value(p.categoryId),
                categoryName: Value(p.categoryName),
                name: p.name,
                description: Value(p.description),
                status: const Value('active'),
                updatedAt: DateTime.now(),
              ),
            );

        for (final v in p.variants) {
          await _db
              .into(_db.localProductVariantSnapshotsTable)
              .insertOnConflictUpdate(
                LocalProductVariantSnapshotsTableCompanion.insert(
                  id: v.id,
                  productId: p.id,
                  name: v.name,
                  sku: Value(v.sku),
                  isDefault: const Value(false),
                  status: const Value('active'),
                ),
              );

          if (v.resolvedPrice != null) {
            await _db.into(_db.localPriceSnapshotsTable).insertOnConflictUpdate(
                  LocalPriceSnapshotsTableCompanion.insert(
                    id: v.resolvedPrice!.priceId,
                    variantId: v.id,
                    outletId: Value(outletId),
                    amountMinor: v.resolvedPrice!.amount,
                    currency: Value(v.resolvedPrice!.currency),
                  ),
                );
          }
        }
      }
    });
  }

  /// Retrieves offline catalog products from local SQLite snapshots.
  Future<List<PosCatalogProduct>> getOfflineCatalog(String outletId,
      {String? tenantId}) async {
    final productQuery = _db.select(_db.localProductSnapshotsTable);
    if (tenantId != null) {
      productQuery.where((t) => t.tenantId.equals(tenantId));
    }
    final productRows = await productQuery.get();
    final variantRows =
        await _db.select(_db.localProductVariantSnapshotsTable).get();
    final priceRows = await (_db.select(_db.localPriceSnapshotsTable)
          ..where((t) => t.outletId.equals(outletId) | t.outletId.isNull()))
        .get();

    final pricesByVariant = <String, PosCatalogPrice>{};
    for (final pr in priceRows) {
      pricesByVariant[pr.variantId] = PosCatalogPrice(
        priceId: pr.id,
        amount: pr.amountMinor,
        currency: pr.currency,
      );
    }

    final variantsByProduct = <String, List<PosCatalogVariant>>{};
    for (final vr in variantRows) {
      final list = variantsByProduct.putIfAbsent(vr.productId, () => []);
      list.add(PosCatalogVariant(
        id: vr.id,
        name: vr.name,
        sku: vr.sku ?? '',
        resolvedPrice: pricesByVariant[vr.id],
      ));
    }

    final results = <PosCatalogProduct>[];
    for (final pr in productRows) {
      results.add(PosCatalogProduct(
        id: pr.id,
        name: pr.name,
        description: pr.description,
        categoryId: pr.categoryId,
        categoryName: pr.categoryName,
        variants: variantsByProduct[pr.id] ?? const [],
        modifierGroups: const [],
      ));
    }

    return results;
  }

  /// Caches customer snapshot locally for offline selection.
  Future<void> saveCustomerSnapshot(
    String tenantId,
    List<CustomerModel> customers,
  ) async {
    await _db.transaction(() async {
      for (final c in customers) {
        await _db.into(_db.localCustomerSnapshotsTable).insertOnConflictUpdate(
              LocalCustomerSnapshotsTableCompanion.insert(
                id: c.id,
                tenantId: tenantId,
                name: c.name,
                phone: Value(c.phone),
                email: Value(c.email),
              ),
            );
      }
    });
  }

  /// Retrieves offline customer snapshot list.
  Future<List<CustomerModel>> getOfflineCustomers(String tenantId) async {
    final rows = await (_db.select(_db.localCustomerSnapshotsTable)
          ..where((t) => t.tenantId.equals(tenantId)))
        .get();

    return rows
        .map((r) => CustomerModel(
              id: r.id,
              tenantId: r.tenantId,
              name: r.name,
              phone: r.phone,
              email: r.email,
              notes: null,
              isActive: true,
              segment: 'prospect',
              marketingOptIn: false,
              createdAt: DateTime.now(),
              updatedAt: DateTime.now(),
            ))
        .toList();
  }

  // -------------------------------------------------------------
  // TRANSACTIONAL OFFLINE SALE FINALIZATION
  // -------------------------------------------------------------

  /// Records an offline finalized sale and its transactional outbox operation
  /// atomically in a single local database transaction.
  /// Complies strictly with PRD Section 1I & ADR-0004.
  Future<PosSale> recordOfflineSale({
    required String tenantId,
    required String outletId,
    required String? shiftId,
    required String cashierUserId,
    required CustomerModel? customer,
    required List<PosCartItem> items,
    required int subtotalMinor,
    required int discountMinor,
    required int taxMinor,
    required int totalMinor,
    required String paymentMethod,
    required int? cashReceivedMinor,
    required int? cashChangeMinor,
    String? notes,
    DateTime? openedAt,
    DateTime? finalizedAt,
  }) async {
    final saleId = _uuid.v7();
    final operationId = _uuid.v7();
    final idempotencyKey = _uuid.v7();

    final now = DateTime.now();
    final effectiveOpenedAt = openedAt ?? now;
    final effectiveFinalizedAt = finalizedAt ?? now;

    final dateStr =
        "${now.year}${now.month.toString().padLeft(2, '0')}${now.day.toString().padLeft(2, '0')}";
    final ref =
        "OFFLINE-SALE-$dateStr-${saleId.substring(saleId.length - 6).toUpperCase()}";

    final saleItems = <PosSaleItem>[];

    await _db.transaction(() async {
      // 1. Insert LocalSale record
      await _db.into(_db.localSalesTable).insert(
            LocalSalesTableCompanion.insert(
              id: saleId,
              tenantId: tenantId,
              outletId: outletId,
              shiftId: Value(shiftId),
              referenceNumber: ref,
              cashierUserId: cashierUserId,
              customerId: Value(customer?.id),
              customerNameSnapshot: Value(customer?.name),
              status: const Value('finalized'),
              syncStatus: Value(OfflineSyncStatus.syncPending.toValue()),
              subtotalMinor: Value(subtotalMinor),
              discountMinor: Value(discountMinor),
              taxMinor: Value(taxMinor),
              totalMinor: Value(totalMinor),
              paymentMethod: Value(paymentMethod),
              cashReceivedMinor: Value(cashReceivedMinor),
              cashChangeMinor: Value(cashChangeMinor),
              notes: Value(notes),
              openedAt: effectiveOpenedAt,
              finalizedAt: Value(effectiveFinalizedAt),
              createdAt: now,
            ),
          );

      // 2. Insert LocalSaleItems
      for (final item in items) {
        final itemId = _uuid.v7();
        final unitPrice = item.totalUnitPriceMinor;
        final itemSubtotal = item.subtotalMinor;

        await _db.into(_db.localSaleItemsTable).insert(
              LocalSaleItemsTableCompanion.insert(
                id: itemId,
                saleId: saleId,
                productVariantId: item.variant.id,
                productNameSnapshot: item.product.name,
                variantNameSnapshot: item.variant.name,
                quantity: item.quantity.toDouble(),
                unitPriceMinor: unitPrice,
                subtotalMinor: itemSubtotal,
                totalMinor: itemSubtotal,
                notes: Value(item.notes),
              ),
            );

        saleItems.add(
          PosSaleItem(
            id: itemId,
            saleId: saleId,
            productVariantId: item.variant.id,
            productNameSnapshot: item.product.name,
            variantNameSnapshot: item.variant.name,
            quantity: item.quantity.toDouble(),
            unitPriceMinor: unitPrice,
            subtotalMinor: itemSubtotal,
            discountMinor: 0,
            totalMinor: itemSubtotal,
            notes: item.notes,
            modifiers: const [],
          ),
        );
      }

      // 3. Insert Transactional Outbox Operation
      final payload = jsonEncode({
        'tenant_id': tenantId,
        'sale_id': saleId,
        'idempotency_key': idempotencyKey,
        'outlet_id': outletId,
        'shift_id': shiftId,
        'customer_id': customer?.id,
        'items': items.map((i) => i.toApiItem()).toList(),
        'discount_minor': discountMinor,
        'tax_minor': taxMinor,
        'payment': {
          'method': paymentMethod,
          'amount_minor': totalMinor,
          'cash_received_minor': cashReceivedMinor ?? totalMinor,
        },
        'notes': notes,
        'opened_at': effectiveOpenedAt.toIso8601String(),
        'finalized_at': effectiveFinalizedAt.toIso8601String(),
      });

      await _db.into(_db.syncOutboxTable).insert(
            SyncOutboxTableCompanion.insert(
              id: operationId,
              commandType: 'sales.finalize',
              aggregateType: 'sale',
              aggregateId: saleId,
              payload: payload,
              createdAt: now,
              state: Value(OutboxState.pending.toValue()),
            ),
          );
    });

    notifyListeners();

    return PosSale(
      id: saleId,
      tenantId: tenantId,
      outletId: outletId,
      referenceNumber: ref,
      status: 'finalized',
      openedAt: effectiveOpenedAt,
      finalizedAt: effectiveFinalizedAt,
      cashierUserId: cashierUserId,
      customerId: customer?.id,
      customerNameSnapshot: customer?.name,
      paymentMethod: paymentMethod,
      subtotalMinor: subtotalMinor,
      discountMinor: discountMinor,
      taxMinor: taxMinor,
      totalMinor: totalMinor,
      notes: notes,
      items: saleItems,
      syncStatus: OfflineSyncStatus.syncPending,
    );
  }

  // -------------------------------------------------------------
  // SYNC WORKER & BATCH PROCESSING
  // -------------------------------------------------------------

  /// Synchronizes eligible pending outbox operations to the backend server.
  /// Handles server responses, causal ordering, error classifications, and retry backoff.
  Future<int> syncPendingOperations({
    String? tenantId,
    String? clientId,
    String? deviceId,
  }) async {
    if (_isSyncing) return 0;

    final allPending = await _outboxService.fetchPendingCommands(limit: 50);
    final pending = tenantId == null
        ? allPending.take(20).toList()
        : allPending.where((cmd) {
            try {
              final data = jsonDecode(cmd.payload) as Map<String, dynamic>;
              return data['tenant_id'] == tenantId;
            } catch (_) {
              return true;
            }
          }).take(20).toList();
    if (pending.isEmpty) return 0;

    _isSyncing = true;
    _lastSyncError = null;
    notifyListeners();

    int successCount = 0;

    try {
      // 1. Mark in-flight commands as uploading
      for (final cmd in pending) {
        await _outboxService.markUploading(cmd.id);

        // Update local sale sync status to syncing
        await (_db.update(_db.localSalesTable)
              ..where((t) => t.id.equals(cmd.aggregateId)))
            .write(LocalSalesTableCompanion(
          syncStatus: Value(OfflineSyncStatus.syncing.toValue()),
        ));
      }

      // 2. Prepare batch payload
      final operationsPayload = pending.map((cmd) {
        final decoded = jsonDecode(cmd.payload) as Map<String, dynamic>;
        final idempotencyKey = decoded['idempotency_key'] as String? ?? cmd.id;
        return {
          'id': cmd.id,
          'command_type': cmd.commandType,
          'aggregate_type': cmd.aggregateType,
          'aggregate_id': cmd.aggregateId,
          'idempotency_key': idempotencyKey,
          'causation_id': cmd.causationId,
          'payload': decoded,
        };
      }).toList();

      final response = await _apiClient.post<dynamic>(
        '/api/v1/sync',
        body: {
          'client_id': clientId ?? 'flutter-pos-client',
          'device_id': deviceId ?? 'flutter-device-01',
          'operations': operationsPayload,
        },
      );

      if (response.isSuccess && response.data != null) {
        final data = response.data as Map<String, dynamic>;
        final results = (data['results'] as List<dynamic>?) ?? [];

        for (final res in results) {
          final resMap = res as Map<String, dynamic>;
          final opId = resMap['operation_id'] as String;
          final saleId = resMap['aggregate_id'] as String;
          final status = resMap['status'] as String;
          final code = resMap['code'] as String?;
          final msg = resMap['message'] as String? ?? '';

          if (status == 'accepted' || status == 'already_processed') {
            await _outboxService.markSynced(opId);
            await (_db.update(_db.localSalesTable)
                  ..where((t) => t.id.equals(saleId)))
                .write(LocalSalesTableCompanion(
              syncStatus: Value(OfflineSyncStatus.synced.toValue()),
              syncedAt: Value(DateTime.now()),
              syncError: const Value(null),
            ));
            successCount++;
          } else if (status == 'blocked' || status == 'rejected') {
            await _outboxService.markRequiresAction(opId, msg);
            await (_db.update(_db.localSalesTable)
                  ..where((t) => t.id.equals(saleId)))
                .write(LocalSalesTableCompanion(
              syncStatus: Value(OfflineSyncStatus.needsReview.toValue()),
              syncError: Value('$code: $msg'),
            ));
          } else {
            // retryable / failed
            await _outboxService.markFailed(opId, msg);
            await (_db.update(_db.localSalesTable)
                  ..where((t) => t.id.equals(saleId)))
                .write(LocalSalesTableCompanion(
              syncStatus: Value(OfflineSyncStatus.failed.toValue()),
              syncError: Value('$code: $msg'),
            ));
          }
        }
        _lastSyncTime = DateTime.now();
      } else {
        // HTTP or server 5xx failure: mark all pending as failed with backoff
        final err = response.message ?? 'Server error during sync.';
        _lastSyncError = err;
        for (final cmd in pending) {
          await _outboxService.markFailed(cmd.id, err);
          await (_db.update(_db.localSalesTable)
                ..where((t) => t.id.equals(cmd.aggregateId)))
              .write(LocalSalesTableCompanion(
            syncStatus: Value(OfflineSyncStatus.syncPending.toValue()),
            syncError: Value(err),
          ));
        }
      }
    } catch (e) {
      _lastSyncError = e.toString();
      // Network timeout or socket exception: preserve local sales and mark failed with backoff
      for (final cmd in pending) {
        try {
          await _outboxService.markFailed(cmd.id, e.toString());
          await (_db.update(_db.localSalesTable)
                ..where((t) => t.id.equals(cmd.aggregateId)))
              .write(LocalSalesTableCompanion(
            syncStatus: Value(OfflineSyncStatus.syncPending.toValue()),
            syncError: Value(e.toString()),
          ));
        } catch (_) {}
      }
    } finally {
      _isSyncing = false;
      notifyListeners();
    }

    return successCount;
  }

  /// Lists local offline sales with their items and sync statuses.
  Future<List<PosSale>> getLocalSales(String outletId,
      {String? tenantId}) async {
    final query = _db.select(_db.localSalesTable)
      ..where((t) => t.outletId.equals(outletId));
    if (tenantId != null) {
      query.where((t) => t.tenantId.equals(tenantId));
    }
    query.orderBy([(t) => OrderingTerm.desc(t.createdAt)]);
    final saleRows = await query.get();

    final results = <PosSale>[];
    for (final r in saleRows) {
      final itemRows = await (_db.select(_db.localSaleItemsTable)
            ..where((t) => t.saleId.equals(r.id)))
          .get();

      final items = itemRows
          .map((i) => PosSaleItem(
                id: i.id,
                saleId: i.saleId,
                productVariantId: i.productVariantId,
                productNameSnapshot: i.productNameSnapshot,
                variantNameSnapshot: i.variantNameSnapshot,
                quantity: i.quantity,
                unitPriceMinor: i.unitPriceMinor,
                subtotalMinor: i.subtotalMinor,
                discountMinor: i.discountMinor,
                totalMinor: i.totalMinor,
                notes: i.notes,
                modifiers: const [],
              ))
          .toList();

      results.add(
        PosSale(
          id: r.id,
          tenantId: r.tenantId,
          outletId: r.outletId,
          referenceNumber: r.referenceNumber,
          status: r.status,
          openedAt: r.openedAt,
          finalizedAt: r.finalizedAt,
          cashierUserId: r.cashierUserId,
          customerId: r.customerId,
          customerNameSnapshot: r.customerNameSnapshot,
          paymentMethod: r.paymentMethod,
          syncedAt: r.syncedAt,
          syncError: r.syncError,
          subtotalMinor: r.subtotalMinor,
          discountMinor: r.discountMinor,
          taxMinor: r.taxMinor,
          totalMinor: r.totalMinor,
          notes: r.notes,
          items: items,
          syncStatus: OfflineSyncStatus.fromValue(r.syncStatus),
        ),
      );
    }

    return results;
  }
}
