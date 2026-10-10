import 'package:flutter/foundation.dart';

@immutable
class CustomerModel {
  final String id;
  final String tenantId;
  final String name;
  final String? phone;
  final String? email;
  final String? notes;
  final bool isActive;
  final String segment;
  final bool marketingOptIn;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  const CustomerModel({
    required this.id,
    required this.tenantId,
    required this.name,
    this.phone,
    this.email,
    this.notes,
    this.isActive = true,
    this.segment = 'prospect',
    this.marketingOptIn = false,
    this.createdAt,
    this.updatedAt,
  });

  factory CustomerModel.fromJson(Map<String, dynamic> json) {
    return CustomerModel(
      id: json['id'] as String,
      tenantId: (json['tenant_id'] as String?) ?? '',
      name: json['name'] as String,
      phone: json['phone'] as String?,
      email: json['email'] as String?,
      notes: json['notes'] as String?,
      isActive: (json['is_active'] as bool?) ?? true,
      segment: (json['segment'] as String?) ?? 'prospect',
      marketingOptIn: (json['marketing_opt_in'] as bool?) ?? false,
      createdAt: json['created_at'] != null ? DateTime.tryParse(json['created_at'] as String) : null,
      updatedAt: json['updated_at'] != null ? DateTime.tryParse(json['updated_at'] as String) : null,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'name': name,
      'phone': phone,
      'email': email,
      'notes': notes,
      'is_active': isActive,
      'segment': segment,
      'marketing_opt_in': marketingOptIn,
    };
  }
}

@immutable
class CustomerSummaryModel {
  final int totalTransactions;
  final int successfulTransactions;
  final int totalSpendMinor;
  final int averageOrderValueMinor;
  final DateTime? firstPurchaseAt;
  final DateTime? lastPurchaseAt;
  final String segment;

  const CustomerSummaryModel({
    required this.totalTransactions,
    required this.successfulTransactions,
    required this.totalSpendMinor,
    required this.averageOrderValueMinor,
    this.firstPurchaseAt,
    this.lastPurchaseAt,
    this.segment = 'prospect',
  });

  factory CustomerSummaryModel.fromJson(Map<String, dynamic> json) {
    return CustomerSummaryModel(
      totalTransactions: (json['total_transactions'] as num?)?.toInt() ?? 0,
      successfulTransactions: (json['successful_transactions'] as num?)?.toInt() ?? 0,
      totalSpendMinor: (json['total_spend_minor'] as num?)?.toInt() ?? 0,
      averageOrderValueMinor: (json['average_order_value_minor'] as num?)?.toInt() ?? 0,
      firstPurchaseAt: json['first_purchase_at'] != null ? DateTime.tryParse(json['first_purchase_at'] as String) : null,
      lastPurchaseAt: json['last_purchase_at'] != null ? DateTime.tryParse(json['last_purchase_at'] as String) : null,
      segment: (json['segment'] as String?) ?? 'prospect',
    );
  }
}

@immutable
class CustomerHistoryItemModel {
  final String saleId;
  final String referenceNumber;
  final DateTime? finalizedAt;
  final DateTime? openedAt;
  final String status;
  final String paymentStatus;
  final String? paymentMethod;
  final int itemCount;
  final int totalMinor;
  final int paidMinor;
  final String outletName;

  const CustomerHistoryItemModel({
    required this.saleId,
    required this.referenceNumber,
    this.finalizedAt,
    this.openedAt,
    required this.status,
    required this.paymentStatus,
    this.paymentMethod,
    required this.itemCount,
    required this.totalMinor,
    required this.paidMinor,
    required this.outletName,
  });

  factory CustomerHistoryItemModel.fromJson(Map<String, dynamic> json) {
    return CustomerHistoryItemModel(
      saleId: json['sale_id'] as String,
      referenceNumber: (json['reference_number'] as String?) ?? '',
      finalizedAt: json['finalized_at'] != null ? DateTime.tryParse(json['finalized_at'] as String) : null,
      openedAt: json['opened_at'] != null ? DateTime.tryParse(json['opened_at'] as String) : null,
      status: (json['status'] as String?) ?? 'open',
      paymentStatus: (json['payment_status'] as String?) ?? 'unpaid',
      paymentMethod: json['payment_method'] as String?,
      itemCount: (json['item_count'] as num?)?.toInt() ?? 0,
      totalMinor: (json['total_minor'] as num?)?.toInt() ?? 0,
      paidMinor: (json['paid_minor'] as num?)?.toInt() ?? 0,
      outletName: (json['outlet_name'] as String?) ?? '',
    );
  }
}
