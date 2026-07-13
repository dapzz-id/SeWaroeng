// Neura Cakrawira Solusi | SeWaroeng
// POS Transaction Hive Model for Offline Local Storage.

import 'package:hive_flutter/hive_flutter.dart';

part 'pos_transaction_hive_model.g.dart';

@HiveType(typeId: 1)
class PosTransactionHiveModel extends HiveObject {
  @HiveField(0)
  final String id;

  @HiveField(1)
  final String tenantId;

  @HiveField(2)
  final String staffId;

  @HiveField(3)
  final double total;

  @HiveField(4)
  final double amountPaid;

  @HiveField(5)
  final double changeDue;

  @HiveField(6)
  final String method; // 'cash', 'qris', 'debit', 'wallet'

  @HiveField(7)
  final String? notes;

  @HiveField(8)
  final List<PosTransactionItemHiveModel> items;

  @HiveField(9)
  final DateTime createdAt;

  @HiveField(10)
  bool isSynced;

  PosTransactionHiveModel({
    required this.id,
    required this.tenantId,
    required this.staffId,
    required this.total,
    required this.amountPaid,
    required this.changeDue,
    required this.method,
    this.notes,
    required this.items,
    required this.createdAt,
    this.isSynced = false,
  });
}

@HiveType(typeId: 2)
class PosTransactionItemHiveModel {
  @HiveField(0)
  final String productId;

  @HiveField(1)
  final String productName;

  @HiveField(2)
  final double price;

  @HiveField(3)
  final int quantity;

  @HiveField(4)
  final double subtotal;

  PosTransactionItemHiveModel({
    required this.productId,
    required this.productName,
    required this.price,
    required this.quantity,
    required this.subtotal,
  });
}
