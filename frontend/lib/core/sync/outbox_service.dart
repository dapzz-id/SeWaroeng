import 'dart:math';
import 'package:drift/drift.dart';
import '../database/database.dart';
import 'outbox_state.dart';

/// Service responsible for transactional outbox queue operations, lifecycle state transitions,
/// causal ordering dependencies, and exponential backoff retry scheduling.
class OutboxService {
  final AppDatabase _db;

  OutboxService(this._db);

  /// Enqueues a new command into the local outbox.
  Future<void> enqueue({
    required String id,
    required String commandType,
    required String aggregateType,
    required String aggregateId,
    required String payload,
    String? causationId,
  }) async {
    await _db
        .into(_db.syncOutboxTable)
        .insert(
          SyncOutboxTableCompanion.insert(
            id: id,
            commandType: commandType,
            aggregateType: aggregateType,
            aggregateId: aggregateId,
            payload: payload,
            createdAt: DateTime.now(),
            state: Value(OutboxState.pending.toValue()),
            causationId: Value(causationId),
          ),
        );
  }

  /// Retrieves the next batch of pending or retry-eligible failed commands ordered by sequence number (FIFO),
  /// while strictly respecting causal dependencies (causation_id must be in `synced` state).
  Future<List<SyncOutboxTableData>> fetchPendingCommands({
    int limit = 20,
  }) async {
    final now = DateTime.now();
    final candidateQuery = _db.select(_db.syncOutboxTable)
      ..where(
        (tbl) =>
            tbl.state.equals(OutboxState.pending.toValue()) |
            (tbl.state.equals(OutboxState.failed.toValue()) &
                tbl.nextAttemptAt.isSmallerOrEqualValue(now)),
      )
      ..orderBy([(tbl) => OrderingTerm.asc(tbl.sequenceNumber)])
      ..limit(limit);

    final candidates = await candidateQuery.get();
    if (candidates.isEmpty) return [];

    final eligible = <SyncOutboxTableData>[];

    for (final item in candidates) {
      if (item.causationId == null) {
        eligible.add(item);
      } else {
        // Causal dependency check: parent command must be terminal 'synced'
        final parent = await (_db.select(
          _db.syncOutboxTable,
        )..where((tbl) => tbl.id.equals(item.causationId!))).getSingleOrNull();

        if (parent != null && parent.state == OutboxState.synced.toValue()) {
          eligible.add(item);
        }
      }
    }

    return eligible;
  }

  /// Transitions command to `uploading` state.
  /// Throws [StateError] if the command is in a terminal state (synced) or unresolvable state.
  Future<void> markUploading(String id) async {
    final item = await (_db.select(
      _db.syncOutboxTable,
    )..where((tbl) => tbl.id.equals(id))).getSingleOrNull();
    if (item == null) {
      throw StateError('Outbox command with id $id not found.');
    }

    if (item.state == OutboxState.synced.toValue()) {
      throw StateError(
        'Invalid state transition: command $id is already synced and terminal.',
      );
    }

    if (item.state == OutboxState.conflict.toValue() ||
        item.state == OutboxState.requiresAction.toValue()) {
      throw StateError(
        'Invalid state transition: command $id is in ${item.state} and requires user resolution before uploading.',
      );
    }

    await (_db.update(
      _db.syncOutboxTable,
    )..where((tbl) => tbl.id.equals(id))).write(
      SyncOutboxTableCompanion(state: Value(OutboxState.uploading.toValue())),
    );
  }

  /// Transitions command to `synced` state upon authoritative 200 OK server response.
  Future<void> markSynced(String id) async {
    await (_db.update(
      _db.syncOutboxTable,
    )..where((tbl) => tbl.id.equals(id))).write(
      SyncOutboxTableCompanion(state: Value(OutboxState.synced.toValue())),
    );
  }

  /// Transitions command to `failed` state with exponential backoff retry calculation.
  Future<void> markFailed(String id, String errorMessage) async {
    final existing = await (_db.select(
      _db.syncOutboxTable,
    )..where((tbl) => tbl.id.equals(id))).getSingleOrNull();
    if (existing == null) return;

    if (existing.state == OutboxState.synced.toValue()) {
      throw StateError('Cannot mark synced command $id as failed.');
    }

    final newAttemptCount = existing.attemptCount + 1;
    // Exponential backoff: min(2^attemptCount * 2 seconds, 300 seconds)
    final delaySeconds = min(pow(2, newAttemptCount) * 2, 300).toInt();
    final nextAttemptAt = DateTime.now().add(Duration(seconds: delaySeconds));

    await (_db.update(
      _db.syncOutboxTable,
    )..where((tbl) => tbl.id.equals(id))).write(
      SyncOutboxTableCompanion(
        state: Value(OutboxState.failed.toValue()),
        attemptCount: Value(newAttemptCount),
        nextAttemptAt: Value(nextAttemptAt),
        lastError: Value(errorMessage),
      ),
    );
  }

  /// Transitions command to `conflict` state (domain validation rejected on server).
  Future<void> markConflict(String id, String errorMessage) async {
    await (_db.update(
      _db.syncOutboxTable,
    )..where((tbl) => tbl.id.equals(id))).write(
      SyncOutboxTableCompanion(
        state: Value(OutboxState.conflict.toValue()),
        lastError: Value(errorMessage),
      ),
    );
  }

  /// Transitions command to `requires_action` state (manual cashier/manager intervention needed).
  Future<void> markRequiresAction(String id, String errorMessage) async {
    await (_db.update(
      _db.syncOutboxTable,
    )..where((tbl) => tbl.id.equals(id))).write(
      SyncOutboxTableCompanion(
        state: Value(OutboxState.requiresAction.toValue()),
        lastError: Value(errorMessage),
      ),
    );
  }

  /// Returns total count of un-synced commands.
  Future<int> getPendingCount() async {
    final count = _db.syncOutboxTable.id.count();
    final query = _db.selectOnly(_db.syncOutboxTable)
      ..where(
        _db.syncOutboxTable.state.equals(OutboxState.synced.toValue()).not(),
      )
      ..addColumns([count]);
    final result = await query.map((row) => row.read(count)).getSingle();
    return result ?? 0;
  }
}
