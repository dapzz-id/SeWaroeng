// Neura Cakrawira Solusi | SeWaroeng
// PosRemoteDataSource — Calls Goravel API for POS transactions

import 'package:flutter_riverpod/flutter_riverpod.dart';
import '../../../../core/network/api_client.dart';
import '../../domain/models/pos_transaction_model.dart';

abstract class PosRemoteDataSource {
  /// Fetch all transactions from server for the current tenant
  Future<List<PosTransactionModel>> getTransactions();

  /// Sync a bulk list of offline transactions to the server
  Future<void> syncTransactions(List<PosTransactionModel> transactions);

  /// Post a single transaction (online checkout)
  Future<PosTransactionModel> postTransaction(PosTransactionModel transaction);
}

class PosRemoteDataSourceImpl implements PosRemoteDataSource {
  final ApiClient _apiClient;

  PosRemoteDataSourceImpl(this._apiClient);

  @override
  Future<List<PosTransactionModel>> getTransactions() async {
    final response = await _apiClient.get<Map<String, dynamic>>('/pos/transactions');
    final data = response.data?['data'] as List<dynamic>? ?? [];
    return data
        .map((e) => PosTransactionModel.fromJson(e as Map<String, dynamic>))
        .toList();
  }

  @override
  Future<void> syncTransactions(List<PosTransactionModel> transactions) async {
    final payload = {
      'transactions': transactions.map((tx) => tx.toJson()).toList(),
    };
    await _apiClient.post<void>('/pos/sync', data: payload);
  }

  @override
  Future<PosTransactionModel> postTransaction(PosTransactionModel transaction) async {
    final response = await _apiClient.post<Map<String, dynamic>>(
      '/pos/transactions',
      data: transaction.toJson(),
    );
    return PosTransactionModel.fromJson(response.data!['data'] as Map<String, dynamic>);
  }
}

final posRemoteDataSourceProvider = Provider<PosRemoteDataSource>((ref) {
  final apiClient = ref.watch(apiClientProvider);
  return PosRemoteDataSourceImpl(apiClient);
});
