class OrderItemModel {
  final String id;
  final String? productVariantId;
  final String productName;
  final String? variantName;
  final double quantity;
  final int unitPriceMinor;
  final int subtotalMinor;
  final String? notes;

  const OrderItemModel({
    required this.id,
    this.productVariantId,
    required this.productName,
    this.variantName,
    required this.quantity,
    required this.unitPriceMinor,
    required this.subtotalMinor,
    this.notes,
  });

  factory OrderItemModel.fromJson(Map<String, dynamic> json) {
    return OrderItemModel(
      id: json['id'] as String? ?? '',
      productVariantId: json['product_variant_id'] as String?,
      productName: json['product_name'] as String? ?? 'Item',
      variantName: json['variant_name'] as String?,
      quantity: (json['quantity'] as num?)?.toDouble() ?? 1.0,
      unitPriceMinor: json['unit_price_minor'] as int? ?? 0,
      subtotalMinor: json['subtotal_minor'] as int? ?? 0,
      notes: json['notes'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'product_variant_id': productVariantId,
        'product_name': productName,
        'variant_name': variantName,
        'quantity': quantity,
        'unit_price_minor': unitPriceMinor,
        'subtotal_minor': subtotalMinor,
        'notes': notes,
      };
}

class OrderModel {
  final String id;
  final String tenantId;
  final String outletId;
  final String? tableId;
  final String? tableName;
  final String orderNumber;
  final String status; // 'pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'
  final int totalMinor;
  final String? customerNotes;
  final String? source;
  final List<OrderItemModel> items;
  final String? createdAt;

  const OrderModel({
    required this.id,
    required this.tenantId,
    required this.outletId,
    this.tableId,
    this.tableName,
    required this.orderNumber,
    required this.status,
    required this.totalMinor,
    this.customerNotes,
    this.source,
    this.items = const [],
    this.createdAt,
  });

  factory OrderModel.fromJson(Map<String, dynamic> json) {
    return OrderModel(
      id: json['id'] as String? ?? '',
      tenantId: json['tenant_id'] as String? ?? '',
      outletId: json['outlet_id'] as String? ?? '',
      tableId: json['table_id'] as String?,
      tableName: json['table'] is Map<String, dynamic>
          ? (json['table']['name'] as String?)
          : null,
      orderNumber: json['order_number'] as String? ?? '',
      status: json['status'] as String? ?? 'pending',
      totalMinor: json['total_minor'] as int? ?? 0,
      customerNotes: json['customer_notes'] as String?,
      source: json['source'] as String?,
      items: (json['items'] as List<dynamic>?)
              ?.map((e) => OrderItemModel.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
      createdAt: json['created_at'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'tenant_id': tenantId,
        'outlet_id': outletId,
        'table_id': tableId,
        'order_number': orderNumber,
        'status': status,
        'total_minor': totalMinor,
        'customer_notes': customerNotes,
        'source': source,
        'items': items.map((i) => i.toJson()).toList(),
      };
}
