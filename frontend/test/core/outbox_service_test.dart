import 'package:drift/native.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/database/database.dart';
import 'package:frontend/core/sync/outbox_service.dart';
import 'package:frontend/core/sync/outbox_state.dart';

void main() {
  late AppDatabase database;
  late OutboxService outboxService;

  setUp(() {
    database = AppDatabase(NativeDatabase.memory());
    outboxService = OutboxService(database);
  });

  tearDown(() async {
    await database.close();
  });

  test('enqueues command into sync outbox with pending state', () async {
    await outboxService.enqueue(
      id: '0192a543-7f89-7e50-93cb-123456789abc',
      commandType: 'orders.create',
      aggregateType: 'order',
      aggregateId: '0192a543-7f89-7e50-93cb-abcdef123456',
      payload: '{"items":[{"sku":"KOPI-001","qty":1}]}',
    );

    final pending = await outboxService.fetchPendingCommands();
    expect(pending.length, equals(1));
    expect(pending.first.id, equals('0192a543-7f89-7e50-93cb-123456789abc'));
    expect(pending.first.state, equals(OutboxState.pending.toValue()));
    expect(pending.first.attemptCount, equals(0));
  });

  test('transitions command through uploading to synced state', () async {
    const commandId = '0192a543-7f89-7e50-93cb-123456789abc';
    await outboxService.enqueue(
      id: commandId,
      commandType: 'orders.create',
      aggregateType: 'order',
      aggregateId: '0192a543-7f89-7e50-93cb-abcdef123456',
      payload: '{"items":[]}',
    );

    await outboxService.markUploading(commandId);
    var item = await (database.select(
      database.syncOutboxTable,
    )..where((tbl) => tbl.id.equals(commandId))).getSingle();
    expect(item.state, equals(OutboxState.uploading.toValue()));

    await outboxService.markSynced(commandId);
    item = await (database.select(
      database.syncOutboxTable,
    )..where((tbl) => tbl.id.equals(commandId))).getSingle();
    expect(item.state, equals(OutboxState.synced.toValue()));

    // Synced item is no longer returned in pending queue
    final pending = await outboxService.fetchPendingCommands();
    expect(pending, isEmpty);
  });

  test(
    'markFailed increments attemptCount and schedules exponential backoff',
    () async {
      const commandId = '0192a543-7f89-7e50-93cb-123456789abc';
      await outboxService.enqueue(
        id: commandId,
        commandType: 'shifts.close',
        aggregateType: 'shift',
        aggregateId: '0192a543-7f89-7e50-93cb-abcdef123456',
        payload: '{"closing_cash":50000}',
      );

      final beforeFail = DateTime.now();
      await outboxService.markFailed(commandId, 'Network unreachable');

      final item = await (database.select(
        database.syncOutboxTable,
      )..where((tbl) => tbl.id.equals(commandId))).getSingle();
      expect(item.state, equals(OutboxState.failed.toValue()));
      expect(item.attemptCount, equals(1));
      expect(item.lastError, equals('Network unreachable'));
      expect(item.nextAttemptAt, isNotNull);
      // Attempt 1: min(2^1 * 2s, 300s) = 4s
      expect(item.nextAttemptAt!.isAfter(beforeFail), isTrue);
    },
  );

  test('transitions command to conflict and requiresAction states', () async {
    const conflictId = '0192a543-0001-7000-8000-000000000001';
    const actionId = '0192a543-0002-7000-8000-000000000002';

    await outboxService.enqueue(
      id: conflictId,
      commandType: 'inventory.adjust',
      aggregateType: 'inventory',
      aggregateId: 'item-1',
      payload: '{}',
    );
    await outboxService.enqueue(
      id: actionId,
      commandType: 'payments.void',
      aggregateType: 'payment',
      aggregateId: 'pay-1',
      payload: '{}',
    );

    await outboxService.markConflict(conflictId, 'Version conflict on server');
    await outboxService.markRequiresAction(actionId, 'Supervisor PIN required');

    final conflictItem = await (database.select(
      database.syncOutboxTable,
    )..where((tbl) => tbl.id.equals(conflictId))).getSingle();
    expect(conflictItem.state, equals(OutboxState.conflict.toValue()));
    expect(conflictItem.lastError, equals('Version conflict on server'));

    final actionItem = await (database.select(
      database.syncOutboxTable,
    )..where((tbl) => tbl.id.equals(actionId))).getSingle();
    expect(actionItem.state, equals(OutboxState.requiresAction.toValue()));
    expect(actionItem.lastError, equals('Supervisor PIN required'));
  });

  test('getPendingCount counts non-synced items', () async {
    expect(await outboxService.getPendingCount(), equals(0));

    await outboxService.enqueue(
      id: 'item-1',
      commandType: 'order.create',
      aggregateType: 'order',
      aggregateId: 'ord-1',
      payload: '{}',
    );
    await outboxService.enqueue(
      id: 'item-2',
      commandType: 'order.create',
      aggregateType: 'order',
      aggregateId: 'ord-2',
      payload: '{}',
    );

    expect(await outboxService.getPendingCount(), equals(2));

    await outboxService.markSynced('item-1');
    expect(await outboxService.getPendingCount(), equals(1));
  });

  test('rejects invalid state transition from synced to uploading', () async {
    const commandId = '0192a543-7f89-7e50-93cb-123456789abc';
    await outboxService.enqueue(
      id: commandId,
      commandType: 'orders.create',
      aggregateType: 'order',
      aggregateId: 'ord-1',
      payload: '{}',
    );

    await outboxService.markUploading(commandId);
    await outboxService.markSynced(commandId);

    // Attempting to re-upload an already synced command MUST throw StateError
    expect(
      () => outboxService.markUploading(commandId),
      throwsA(isA<StateError>()),
    );
  });

  test('respects causal ordering dependencies between commands', () async {
    const parentId = '0192a543-0000-7000-8000-000000000001';
    const childId = '0192a543-0000-7000-8000-000000000002';

    // Enqueue parent command (e.g. shift open)
    await outboxService.enqueue(
      id: parentId,
      commandType: 'shifts.open',
      aggregateType: 'shift',
      aggregateId: 'shift-1',
      payload: '{"opening_cash":100000}',
    );

    // Enqueue dependent child command with causationId = parentId (e.g. order placed in that shift)
    await outboxService.enqueue(
      id: childId,
      commandType: 'orders.create',
      aggregateType: 'order',
      aggregateId: 'order-1',
      payload: '{"total":25000}',
      causationId: parentId,
    );

    // Initially, only parent is eligible because parent is not yet synced!
    var eligible = await outboxService.fetchPendingCommands();
    expect(eligible.length, equals(1));
    expect(eligible.first.id, equals(parentId));

    // Mark parent as synced
    await outboxService.markUploading(parentId);
    await outboxService.markSynced(parentId);

    // Now child becomes eligible because its causal dependency has been satisfied!
    eligible = await outboxService.fetchPendingCommands();
    expect(eligible.length, equals(1));
    expect(eligible.first.id, equals(childId));
  });
}
