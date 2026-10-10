import 'dart:io';
import 'package:drift/drift.dart';
import 'package:drift/native.dart';
import 'package:path_provider/path_provider.dart';
import 'package:path/path.dart' as p;

import 'database_encryption.dart';

part 'database.g.dart';

/// Table representing the transactional outbox queue for offline-first commands.
/// Complies with ADR-0004.
class SyncOutboxTable extends Table {
  @override
  String get tableName => 'sync_outbox';

  /// Client-generated UUIDv7 command identifier
  TextColumn get id => text()();

  /// e.g. 'sales.finalize', 'sales.create'
  TextColumn get commandType => text().named('command_type')();

  /// e.g. 'sale'
  TextColumn get aggregateType => text().named('aggregate_type')();

  /// Aggregate root UUIDv7
  TextColumn get aggregateId => text().named('aggregate_id')();

  /// JSON serialized command payload
  TextColumn get payload => text()();

  /// Creation timestamp
  DateTimeColumn get createdAt => dateTime().named('created_at')();

  /// Number of sync attempts performed
  IntColumn get attemptCount =>
      integer().named('attempt_count').withDefault(const Constant(0))();

  /// Next retry scheduled timestamp for backoff
  DateTimeColumn get nextAttemptAt =>
      dateTime().nullable().named('next_attempt_at')();

  /// State enum value: pending, uploading, synced, failed, conflict, requires_action
  TextColumn get state => text().withDefault(const Constant('pending'))();

  /// Last error message from server or network
  TextColumn get lastError => text().nullable().named('last_error')();

  /// Parent command ID for causal ordering dependencies
  TextColumn get causationId => text().nullable().named('causation_id')();

  /// Monotonically increasing sequence number per device
  IntColumn get sequenceNumber =>
      integer().named('sequence_number').withDefault(const Constant(0))();

  @override
  Set<Column> get primaryKey => {id};
}

/// Table storing local application session state (e.g. active tenant, active outlet, cashier token)
class SessionStateTable extends Table {
  @override
  String get tableName => 'session_state';

  TextColumn get key => text()();
  TextColumn get value => text()();
  DateTimeColumn get updatedAt => dateTime().named('updated_at')();

  @override
  Set<Column> get primaryKey => {key};
}

/// Table storing sync watermarks, cursors, and last pull timestamps
class SyncMetadataTable extends Table {
  @override
  String get tableName => 'sync_metadata';

  TextColumn get key => text()();
  DateTimeColumn get lastSyncedAt =>
      dateTime().nullable().named('last_synced_at')();
  TextColumn get cursor => text().nullable()();

  @override
  Set<Column> get primaryKey => {key};
}

/// Local product snapshot for offline catalog browsing.
class LocalProductSnapshotsTable extends Table {
  @override
  String get tableName => 'local_product_snapshots';

  TextColumn get id => text()();
  TextColumn get tenantId => text().named('tenant_id')();
  TextColumn get categoryId => text().nullable().named('category_id')();
  TextColumn get categoryName => text().nullable().named('category_name')();
  TextColumn get name => text()();
  TextColumn get description => text().nullable()();
  TextColumn get status => text().withDefault(const Constant('active'))();
  DateTimeColumn get updatedAt => dateTime().named('updated_at')();

  @override
  Set<Column> get primaryKey => {id};
}

/// Local product variant snapshot.
class LocalProductVariantSnapshotsTable extends Table {
  @override
  String get tableName => 'local_product_variant_snapshots';

  TextColumn get id => text()();
  TextColumn get productId => text().named('product_id')();
  TextColumn get name => text()();
  TextColumn get sku => text().nullable()();
  BoolColumn get isDefault =>
      boolean().named('is_default').withDefault(const Constant(false))();
  TextColumn get status => text().withDefault(const Constant('active'))();

  @override
  Set<Column> get primaryKey => {id};
}

/// Local price snapshot per variant and outlet.
class LocalPriceSnapshotsTable extends Table {
  @override
  String get tableName => 'local_price_snapshots';

  TextColumn get id => text()();
  TextColumn get variantId => text().named('variant_id')();
  TextColumn get outletId => text().nullable().named('outlet_id')();
  IntColumn get amountMinor => integer().named('amount_minor')();
  TextColumn get currency => text().withDefault(const Constant('IDR'))();

  @override
  Set<Column> get primaryKey => {id};
}

/// Local inventory snapshot for local stock guidance.
class LocalInventorySnapshotsTable extends Table {
  @override
  String get tableName => 'local_inventory_snapshots';

  TextColumn get id => text()();
  TextColumn get inventoryItemId => text().named('inventory_item_id')();
  TextColumn get locationId => text().named('location_id')();
  TextColumn get outletId => text().named('outlet_id')();
  TextColumn get name => text()();
  TextColumn get sku => text().nullable()();
  RealColumn get onHand =>
      real().named('on_hand').withDefault(const Constant(0.0))();
  RealColumn get reserved => real().withDefault(const Constant(0.0))();
  TextColumn get unit => text().withDefault(const Constant('pcs'))();

  @override
  Set<Column> get primaryKey => {id};
}

/// Local customer snapshot for offline selection.
class LocalCustomerSnapshotsTable extends Table {
  @override
  String get tableName => 'local_customer_snapshots';

  TextColumn get id => text()();
  TextColumn get tenantId => text().named('tenant_id')();
  TextColumn get name => text()();
  TextColumn get phone => text().nullable()();
  TextColumn get email => text().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}

/// Local active cart header.
class LocalCartsTable extends Table {
  @override
  String get tableName => 'local_carts';

  TextColumn get id => text()();
  TextColumn get outletId => text().named('outlet_id')();
  TextColumn get customerId => text().nullable().named('customer_id')();
  IntColumn get discountMinor =>
      integer().named('discount_minor').withDefault(const Constant(0))();
  IntColumn get taxMinor =>
      integer().named('tax_minor').withDefault(const Constant(0))();
  TextColumn get notes => text().nullable()();
  DateTimeColumn get updatedAt => dateTime().named('updated_at')();

  @override
  Set<Column> get primaryKey => {id};
}

/// Local active cart items.
class LocalCartItemsTable extends Table {
  @override
  String get tableName => 'local_cart_items';

  TextColumn get id => text()();
  TextColumn get cartId => text().named('cart_id')();
  TextColumn get productVariantId => text().named('product_variant_id')();
  TextColumn get productName => text().named('product_name')();
  TextColumn get variantName => text().named('variant_name')();
  IntColumn get unitPriceMinor => integer().named('unit_price_minor')();
  IntColumn get quantity => integer().withDefault(const Constant(1))();
  TextColumn get notes => text().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}

/// Local sales table for offline POS transactions.
class LocalSalesTable extends Table {
  @override
  String get tableName => 'local_sales';

  /// Client-generated UUIDv7 identifier
  TextColumn get id => text()();
  TextColumn get tenantId => text().named('tenant_id')();
  TextColumn get outletId => text().named('outlet_id')();
  TextColumn get shiftId => text().nullable().named('shift_id')();
  TextColumn get referenceNumber => text().named('reference_number')();
  TextColumn get cashierUserId => text().named('cashier_user_id')();
  TextColumn get customerId => text().nullable().named('customer_id')();
  TextColumn get customerNameSnapshot =>
      text().nullable().named('customer_name_snapshot')();
  TextColumn get status => text().withDefault(const Constant('open'))();
  TextColumn get syncStatus =>
      text().named('sync_status').withDefault(const Constant('pending'))();
  IntColumn get subtotalMinor =>
      integer().named('subtotal_minor').withDefault(const Constant(0))();
  IntColumn get discountMinor =>
      integer().named('discount_minor').withDefault(const Constant(0))();
  IntColumn get taxMinor =>
      integer().named('tax_minor').withDefault(const Constant(0))();
  IntColumn get totalMinor =>
      integer().named('total_minor').withDefault(const Constant(0))();
  TextColumn get paymentMethod =>
      text().nullable().named('payment_method')();
  IntColumn get cashReceivedMinor =>
      integer().nullable().named('cash_received_minor')();
  IntColumn get cashChangeMinor =>
      integer().nullable().named('cash_change_minor')();
  TextColumn get notes => text().nullable()();
  DateTimeColumn get openedAt => dateTime().named('opened_at')();
  DateTimeColumn get finalizedAt =>
      dateTime().nullable().named('finalized_at')();
  DateTimeColumn get syncedAt => dateTime().nullable().named('synced_at')();
  TextColumn get syncError => text().nullable().named('sync_error')();
  DateTimeColumn get createdAt => dateTime().named('created_at')();

  @override
  Set<Column> get primaryKey => {id};
}

/// Local sale line items.
class LocalSaleItemsTable extends Table {
  @override
  String get tableName => 'local_sale_items';

  /// Client-generated UUIDv7 identifier
  TextColumn get id => text()();
  TextColumn get saleId => text().named('sale_id')();
  TextColumn get productVariantId => text().named('product_variant_id')();
  TextColumn get productNameSnapshot =>
      text().named('product_name_snapshot')();
  TextColumn get variantNameSnapshot =>
      text().named('variant_name_snapshot')();
  RealColumn get quantity => real()();
  IntColumn get unitPriceMinor => integer().named('unit_price_minor')();
  IntColumn get subtotalMinor => integer().named('subtotal_minor')();
  IntColumn get discountMinor =>
      integer().named('discount_minor').withDefault(const Constant(0))();
  IntColumn get totalMinor => integer().named('total_minor')();
  TextColumn get notes => text().nullable()();

  @override
  Set<Column> get primaryKey => {id};
}

@DriftDatabase(tables: [
  SyncOutboxTable,
  SessionStateTable,
  SyncMetadataTable,
  LocalProductSnapshotsTable,
  LocalProductVariantSnapshotsTable,
  LocalPriceSnapshotsTable,
  LocalInventorySnapshotsTable,
  LocalCustomerSnapshotsTable,
  LocalCartsTable,
  LocalCartItemsTable,
  LocalSalesTable,
  LocalSaleItemsTable,
])
class AppDatabase extends _$AppDatabase {
  AppDatabase([QueryExecutor? e]) : super(e ?? _openConnection());

  @override
  int get schemaVersion => 2;

  @override
  MigrationStrategy get migration => MigrationStrategy(
        onCreate: (m) async {
          await m.createAll();
        },
        onUpgrade: (m, from, to) async {
          if (from < 2) {
            await m.createTable(localProductSnapshotsTable);
            await m.createTable(localProductVariantSnapshotsTable);
            await m.createTable(localPriceSnapshotsTable);
            await m.createTable(localInventorySnapshotsTable);
            await m.createTable(localCustomerSnapshotsTable);
            await m.createTable(localCartsTable);
            await m.createTable(localCartItemsTable);
            await m.createTable(localSalesTable);
            await m.createTable(localSaleItemsTable);
          }
        },
      );

  static LazyDatabase _openConnection([DatabaseEncryptionKeyManager? keyManager]) {
    return LazyDatabase(() async {
      final dbFolder = await getApplicationDocumentsDirectory();
      final file = File(p.join(dbFolder.path, 'sewaroeng_local.sqlite'));
      final km = keyManager ?? DatabaseEncryptionKeyManager();
      final encryptionKey = await km.getOrCreateDatabaseKey();
      return NativeDatabase.createInBackground(
        file,
        setup: (rawDb) {
          DatabaseEncryptionKeyManager.applyEncryptionPragma(rawDb, encryptionKey);
        },
      );
    });
  }
}
