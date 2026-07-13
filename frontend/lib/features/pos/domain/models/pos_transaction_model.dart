// Neura Cakrawira Solusi | SeWaroeng
// POS Domain Models — PosTransactionModel & PosReceiptModel

class PosTransactionItemModel {
  final String productId;
  final String productName;
  final double price;
  final int quantity;
  final double subtotal;

  const PosTransactionItemModel({
    required this.productId,
    required this.productName,
    required this.price,
    required this.quantity,
    required this.subtotal,
  });

  factory PosTransactionItemModel.fromJson(Map<String, dynamic> json) {
    return PosTransactionItemModel(
      productId: json['product_id'] as String,
      productName: json['product_name'] as String,
      price: (json['price'] as num).toDouble(),
      quantity: json['quantity'] as int,
      subtotal: (json['subtotal'] as num).toDouble(),
    );
  }

  Map<String, dynamic> toJson() => {
        'product_id': productId,
        'product_name': productName,
        'price': price,
        'quantity': quantity,
        'subtotal': subtotal,
      };
}

class PosTransactionModel {
  final String id;
  final String tenantId;
  final String staffId;
  final double total;
  final double amountPaid;
  final double changeDue;
  final String method; // cash, qris, debit, wallet
  final String? notes;
  final List<PosTransactionItemModel> items;
  final DateTime createdAt;
  final bool isSynced;

  const PosTransactionModel({
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

  factory PosTransactionModel.fromJson(Map<String, dynamic> json) {
    return PosTransactionModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String,
      staffId: json['staff_id'] as String,
      total: (json['total'] as num).toDouble(),
      amountPaid: (json['amount_paid'] as num).toDouble(),
      changeDue: (json['change_due'] as num).toDouble(),
      method: json['method'] as String,
      notes: json['notes'] as String?,
      items: (json['items'] as List<dynamic>)
          .map((e) => PosTransactionItemModel.fromJson(e as Map<String, dynamic>))
          .toList(),
      createdAt: DateTime.parse(json['created_at'] as String),
      isSynced: json['is_synced'] as bool? ?? true,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'tenant_id': tenantId,
        'staff_id': staffId,
        'total': total,
        'amount_paid': amountPaid,
        'change_due': changeDue,
        'method': method,
        'notes': notes,
        'items': items.map((e) => e.toJson()).toList(),
        'created_at': createdAt.toIso8601String(),
        'is_synced': isSynced,
      };
}

class PosReceiptModel {
  final String transactionId;
  final String storeName;
  final String staffName;
  final DateTime date;
  final List<PosTransactionItemModel> items;
  final double subtotal;
  final double discount;
  final double total;
  final double amountPaid;
  final double changeDue;
  final String method;
  final String? notes;

  const PosReceiptModel({
    required this.transactionId,
    required this.storeName,
    required this.staffName,
    required this.date,
    required this.items,
    required this.subtotal,
    required this.discount,
    required this.total,
    required this.amountPaid,
    required this.changeDue,
    required this.method,
    this.notes,
  });

  factory PosReceiptModel.fromTransaction(
    PosTransactionModel tx, {
    required String storeName,
    required String staffName,
    double discount = 0.0,
  }) {
    return PosReceiptModel(
      transactionId: tx.id,
      storeName: storeName,
      staffName: staffName,
      date: tx.createdAt,
      items: tx.items,
      subtotal: tx.total + discount,
      discount: discount,
      total: tx.total,
      amountPaid: tx.amountPaid,
      changeDue: tx.changeDue,
      method: tx.method,
      notes: tx.notes,
    );
  }
}
