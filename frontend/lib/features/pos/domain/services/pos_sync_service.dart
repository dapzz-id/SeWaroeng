// Neura Cakrawira Solusi | SeWaroeng
// POS Sync Service (Offline to Online)

import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:sewaroeng/core/network/api_client.dart';
import '../../data/datasources/pos_local_datasource.dart';

class PosSyncService {
  final PosLocalDataSource _localDataSource;
  final ApiClient _apiClient;

  PosSyncService(this._localDataSource, this._apiClient);

  Future<void> syncOfflineTransactions() async {
    try {
      final unsynced = await _localDataSource.getUnsyncedTransactions();
      if (unsynced.isEmpty) return;

      // Group into a bulk payload
      final payload = unsynced.map((tx) {
        return {
          'id': tx.id,
          'tenant_id': tx.tenantId,
          'staff_id': tx.staffId,
          'total': tx.total,
          'amount_paid': tx.amountPaid,
          'change_due': tx.changeDue,
          'method': tx.method,
          'notes': tx.notes,
          'created_at': tx.createdAt.toIso8601String(),
          'items': tx.items.map((item) {
            return {
              'product_id': item.productId,
              'product_name': item.productName,
              'price': item.price,
              'quantity': item.quantity,
              'subtotal': item.subtotal,
            };
          }).toList(),
        };
      }).toList();

      // Send to backend (Assuming there's a bulk sync endpoint or loop)
      // Goravel backend currently has /pos/sync for bulk
      final response =
          await _apiClient.post('/pos/sync', data: {'transactions': payload});

      if (response.statusCode == 200 || response.statusCode == 201) {
        // Mark as synced locally
        for (var tx in unsynced) {
          await _localDataSource.markAsSynced(tx.id);
        }
      }
    } catch (e) {
      // Failed to sync, keep them locally for next time
      debugPrint("POS Sync Failed: $e");
    }
  }
}

final posSyncServiceProvider = Provider<PosSyncService>((ref) {
  final localDs = ref.watch(posLocalDataSourceProvider);
  final apiClient = ref.watch(apiClientProvider);
  return PosSyncService(localDs, apiClient);
});
