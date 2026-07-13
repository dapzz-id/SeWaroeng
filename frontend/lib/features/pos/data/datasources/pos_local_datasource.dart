// Neura Cakrawira Solusi | SeWaroeng
// POS Local Datasource using Hive (AES-256 Encrypted)

import 'package:hive_flutter/hive_flutter.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../models/pos_transaction_hive_model.dart';

abstract class PosLocalDataSource {
  Future<void> saveTransaction(PosTransactionHiveModel transaction);
  Future<List<PosTransactionHiveModel>> getUnsyncedTransactions();
  Future<void> markAsSynced(String transactionId);
  Future<void> clearSyncedTransactions();
}

class PosLocalDataSourceImpl implements PosLocalDataSource {
  final Box<PosTransactionHiveModel> _box;

  PosLocalDataSourceImpl(this._box);

  @override
  Future<void> saveTransaction(PosTransactionHiveModel transaction) async {
    await _box.put(transaction.id, transaction);
  }

  @override
  Future<List<PosTransactionHiveModel>> getUnsyncedTransactions() async {
    return _box.values.where((tx) => !tx.isSynced).toList();
  }

  @override
  Future<void> markAsSynced(String transactionId) async {
    final tx = _box.get(transactionId);
    if (tx != null) {
      tx.isSynced = true;
      await tx.save(); // Requires HiveObject mixin
    }
  }

  @override
  Future<void> clearSyncedTransactions() async {
    final syncedKeys = _box.values
        .where((tx) => tx.isSynced)
        .map((tx) => tx.key)
        .toList();
    await _box.deleteAll(syncedKeys);
  }
}

// Provider for PosLocalDataSource
final posLocalDataSourceProvider = Provider<PosLocalDataSource>((ref) {
  final box = Hive.box<PosTransactionHiveModel>('pos_transactions');
  return PosLocalDataSourceImpl(box);
});
