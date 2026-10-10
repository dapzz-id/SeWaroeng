import 'package:flutter/foundation.dart';

@immutable
class ReceiptItemModel {
  final String name;
  final String? variantName;
  final double quantity;
  final int unitPriceMinor;
  final int discountMinor;
  final int subtotalMinor;
  final String? notes;

  const ReceiptItemModel({
    required this.name,
    this.variantName,
    required this.quantity,
    required this.unitPriceMinor,
    required this.discountMinor,
    required this.subtotalMinor,
    this.notes,
  });

  factory ReceiptItemModel.fromJson(Map<String, dynamic> json) {
    return ReceiptItemModel(
      name: json['name'] as String,
      variantName: json['variant_name'] as String?,
      quantity: (json['quantity'] as num?)?.toDouble() ?? 1.0,
      unitPriceMinor: (json['unit_price_minor'] as num?)?.toInt() ?? 0,
      discountMinor: (json['discount_minor'] as num?)?.toInt() ?? 0,
      subtotalMinor: (json['subtotal_minor'] as num?)?.toInt() ?? 0,
      notes: json['notes'] as String?,
    );
  }
}

@immutable
class ReceiptPaymentModel {
  final String method;
  final int amountMinor;
  final String status;
  final DateTime? paidAt;
  final int changeMinor;

  const ReceiptPaymentModel({
    required this.method,
    required this.amountMinor,
    required this.status,
    this.paidAt,
    this.changeMinor = 0,
  });

  factory ReceiptPaymentModel.fromJson(Map<String, dynamic> json) {
    return ReceiptPaymentModel(
      method: (json['method'] as String?) ?? 'cash',
      amountMinor: (json['amount_minor'] as num?)?.toInt() ?? 0,
      status: (json['status'] as String?) ?? 'succeeded',
      paidAt: json['paid_at'] != null ? DateTime.tryParse(json['paid_at'] as String) : null,
      changeMinor: (json['change_minor'] as num?)?.toInt() ?? 0,
    );
  }
}

@immutable
class ReceiptModel {
  final String receiptNumber;
  final String saleReferenceNumber;
  final DateTime? issuedAt;
  final int reprintCount;
  final String businessName;
  final String outletName;
  final String? outletAddress;
  final String? outletPhone;
  final String cashierName;
  final String? customerName;
  final String? customerPhone;
  final List<ReceiptItemModel> items;
  final int subtotalMinor;
  final int discountMinor;
  final int taxMinor;
  final int totalMinor;
  final List<ReceiptPaymentModel> payments;
  final String status;
  final bool isCancelled;

  const ReceiptModel({
    required this.receiptNumber,
    required this.saleReferenceNumber,
    this.issuedAt,
    this.reprintCount = 0,
    required this.businessName,
    required this.outletName,
    this.outletAddress,
    this.outletPhone,
    required this.cashierName,
    this.customerName,
    this.customerPhone,
    required this.items,
    required this.subtotalMinor,
    required this.discountMinor,
    required this.taxMinor,
    required this.totalMinor,
    required this.payments,
    required this.status,
    this.isCancelled = false,
  });

  bool get isReprint => reprintCount > 0;

  factory ReceiptModel.fromJson(Map<String, dynamic> json) {
    final business = json['business'] as Map<String, dynamic>? ?? {};
    final outlet = json['outlet'] as Map<String, dynamic>? ?? {};
    final customer = json['customer'] as Map<String, dynamic>?;
    final pricing = json['pricing'] as Map<String, dynamic>? ?? {};

    final rawItems = json['items'] as List<dynamic>? ?? [];
    final itemsList = rawItems.map((e) => ReceiptItemModel.fromJson(e as Map<String, dynamic>)).toList();

    final rawPayments = json['payments'] as List<dynamic>? ?? [];
    final paymentsList = rawPayments.map((e) => ReceiptPaymentModel.fromJson(e as Map<String, dynamic>)).toList();

    return ReceiptModel(
      receiptNumber: (json['receipt_number'] as String?) ?? '',
      saleReferenceNumber: (json['sale_reference_number'] as String?) ?? '',
      issuedAt: json['issued_at'] != null ? DateTime.tryParse(json['issued_at'] as String) : null,
      reprintCount: (json['reprint_count'] as num?)?.toInt() ?? 0,
      businessName: (business['name'] as String?) ?? 'Waroeng',
      outletName: (outlet['name'] as String?) ?? 'Outlet',
      outletAddress: outlet['address'] as String?,
      outletPhone: outlet['phone'] as String?,
      cashierName: (json['cashier_name'] as String?) ?? 'Kasir',
      customerName: customer?['name'] as String?,
      customerPhone: customer?['phone'] as String?,
      items: itemsList,
      subtotalMinor: (pricing['subtotal_minor'] as num?)?.toInt() ?? 0,
      discountMinor: (pricing['discount_minor'] as num?)?.toInt() ?? 0,
      taxMinor: (pricing['tax_minor'] as num?)?.toInt() ?? 0,
      totalMinor: (pricing['total_minor'] as num?)?.toInt() ?? 0,
      payments: paymentsList,
      status: (json['status'] as String?) ?? 'finalized',
      isCancelled: (json['is_cancelled'] as bool?) ?? false,
    );
  }
}
