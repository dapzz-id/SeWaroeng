// Neura Cakrawira Solusi | SeWaroeng
// PosRepository — Offline-First pattern: writes go to Hive first, synced to API when online.

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../data/datasources/pos_local_datasource.dart';
import '../../data/datasources/pos_remote_datasource.dart';
import '../../data/models/pos_transaction_hive_model.dart';
import '../models/pos_transaction_model.dart';

abstract class PosRepository {
  /// Save a transaction offline first, then attempt sync if online.
  Future<void> saveTransaction(PosTransactionModel transaction);

  /// Attempt to sync all unsynced Hive records to the server.
  Future<void> syncPending();

  /// Fetch transactions from remote (falls back to local if offline).
  Future<List<PosTransactionModel>> getTransactions();
}

class PosRepositoryImpl implements PosRepository {
  final PosLocalDataSource _local;
  final PosRemoteDataSource _remote;

  PosRepositoryImpl({required PosLocalDataSource local, required PosRemoteDataSource remote})
      : _local = local,
        _remote = remote;

  @override
  Future<void> saveTransaction(PosTransactionModel transaction) async {
    // Always persist to Hive first (offline-safe)
    final hiveModel = PosTransactionHiveModel(
      id: transaction.id,
      tenantId: transaction.tenantId,
      staffId: transaction.staffId,
      total: transaction.total,
      amountPaid: transaction.amountPaid,
      changeDue: transaction.changeDue,
      method: transaction.method,
      notes: transaction.notes,
      items: transaction.items
          .map((item) => PosTransactionItemHiveModel(
                productId: item.productId,
                productName: item.productName,
                price: item.price,
                quantity: item.quantity,
                subtotal: item.subtotal,
              ))
          .toList(),
      createdAt: transaction.createdAt,
      isSynced: false,
    );
    await _local.saveTransaction(hiveModel);

    // Attempt immediate sync if online
    final connectivity = await Connectivity().checkConnectivity();
    if (connectivity.contains(ConnectivityResult.none) == false) {
      await syncPending();
    }
  }

  @override
  Future<void> syncPending() async {
    try {
      final unsynced = await _local.getUnsyncedTransactions();
      if (unsynced.isEmpty) return;

      final models = unsynced
          .map((h) => PosTransactionModel(
                id: h.id,
                tenantId: h.tenantId,
                staffId: h.staffId,
                total: h.total,
                amountPaid: h.amountPaid,
                changeDue: h.changeDue,
                method: h.method,
                notes: h.notes,
                items: h.items
                    .map((item) => PosTransactionItemModel(
                          productId: item.productId,
                          productName: item.productName,
                          price: item.price,
                          quantity: item.quantity,
                          subtotal: item.subtotal,
                        ))
                    .toList(),
                createdAt: h.createdAt,
                isSynced: false,
              ))
          .toList();

      await _remote.syncTransactions(models);

      // Mark all as synced
      for (final h in unsynced) {
        await _local.markAsSynced(h.id);
      }
    } catch (e) {
      debugPrint('[PosRepository] syncPending failed: $e');
    }
  }

  @override
  Future<List<PosTransactionModel>> getTransactions() async {
    try {
      // Try remote first
      final connectivity = await Connectivity().checkConnectivity();
      if (!connectivity.contains(ConnectivityResult.none)) {
        return await _remote.getTransactions();
      }
    } catch (_) {
      // Fallback to local below
    }
    // Return local records as models
    final localItems = await _local.getUnsyncedTransactions();
    return localItems
        .map((h) => PosTransactionModel(
              id: h.id,
              tenantId: h.tenantId,
              staffId: h.staffId,
              total: h.total,
              amountPaid: h.amountPaid,
              changeDue: h.changeDue,
              method: h.method,
              notes: h.notes,
              items: h.items
                  .map((item) => PosTransactionItemModel(
                        productId: item.productId,
                        productName: item.productName,
                        price: item.price,
                        quantity: item.quantity,
                        subtotal: item.subtotal,
                      ))
                  .toList(),
              createdAt: h.createdAt,
              isSynced: false,
            ))
        .toList();
  }
}

final posRepositoryProvider = Provider<PosRepository>((ref) {
  return PosRepositoryImpl(
    local: ref.watch(posLocalDataSourceProvider),
    remote: ref.watch(posRemoteDataSourceProvider),
  );
});
