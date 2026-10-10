class SupplierModel {
  final String id;
  final String tenantId;
  final String code;
  final String name;
  final String? contactName;
  final String? phone;
  final String? email;
  final String? address;
  final String? taxIdentifier;
  final int? paymentTermsDays;
  final String? notes;
  final String status;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  const SupplierModel({
    required this.id,
    required this.tenantId,
    required this.code,
    required this.name,
    this.contactName,
    this.phone,
    this.email,
    this.address,
    this.taxIdentifier,
    this.paymentTermsDays,
    this.notes,
    required this.status,
    this.createdAt,
    this.updatedAt,
  });

  bool get isActive => status == 'active';

  factory SupplierModel.fromJson(Map<String, dynamic> json) {
    return SupplierModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      contactName: json['contact_name'] as String?,
      phone: json['phone'] as String?,
      email: json['email'] as String?,
      address: json['address'] as String?,
      taxIdentifier: json['tax_identifier'] as String?,
      paymentTermsDays: json['payment_terms_days'] as int?,
      notes: json['notes'] as String?,
      status: json['status'] as String? ?? 'active',
      createdAt: json['created_at'] != null
          ? DateTime.tryParse(json['created_at'] as String)
          : null,
      updatedAt: json['updated_at'] != null
          ? DateTime.tryParse(json['updated_at'] as String)
          : null,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'code': code,
      'name': name,
      'contact_name': contactName,
      'phone': phone,
      'email': email,
      'address': address,
      'tax_identifier': taxIdentifier,
      'payment_terms_days': paymentTermsDays,
      'notes': notes,
      'status': status,
    };
  }
}

class PurchaseOrderItemModel {
  final String id;
  final String tenantId;
  final String purchaseOrderId;
  final String inventoryItemId;
  final String? inventoryItemName;
  final String? inventoryItemSku;
  final String? baseUnit;
  final double orderedQuantity;
  final int unitCostMinor;
  final double receivedQuantity;
  final double remainingQuantity;
  final String? notes;

  const PurchaseOrderItemModel({
    required this.id,
    required this.tenantId,
    required this.purchaseOrderId,
    required this.inventoryItemId,
    this.inventoryItemName,
    this.inventoryItemSku,
    this.baseUnit,
    required this.orderedQuantity,
    required this.unitCostMinor,
    required this.receivedQuantity,
    required this.remainingQuantity,
    this.notes,
  });

  String get formattedUnitCost {
    if (unitCostMinor <= 0) {
      return 'Biaya Belum Ditentukan';
    }
    final formatted = unitCostMinor.toString().replaceAllMapped(
      RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'),
      (Match m) => '${m[1]}.',
    );
    return 'Rp $formatted';
  }

  factory PurchaseOrderItemModel.fromJson(Map<String, dynamic> json) {
    final itemData = json['inventory_item'] as Map<String, dynamic>?;
    final orderedQty =
        double.tryParse(json['ordered_quantity']?.toString() ?? '0') ?? 0.0;
    final receivedQty =
        double.tryParse(json['received_quantity']?.toString() ?? '0') ?? 0.0;
    final remainingQty = json['remaining_quantity'] != null
        ? (double.tryParse(json['remaining_quantity'].toString()) ?? 0.0)
        : (orderedQty - receivedQty).clamp(0.0, double.infinity);

    return PurchaseOrderItemModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      purchaseOrderId: json['purchase_order_id'] as String? ?? '',
      inventoryItemId: json['inventory_item_id'] as String? ?? '',
      inventoryItemName: itemData?['name'] as String?,
      inventoryItemSku: itemData?['sku'] as String?,
      baseUnit: itemData?['base_unit'] as String?,
      orderedQuantity: orderedQty,
      unitCostMinor: (json['unit_cost_minor'] as num?)?.toInt() ?? 0,
      receivedQuantity: receivedQty,
      remainingQuantity: remainingQty,
      notes: json['notes'] as String?,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'purchase_order_id': purchaseOrderId,
      'inventory_item_id': inventoryItemId,
      'ordered_quantity': orderedQuantity,
      'unit_cost_minor': unitCostMinor,
      'received_quantity': receivedQuantity,
      'remaining_quantity': remainingQuantity,
      'notes': notes,
    };
  }
}

class PurchaseOrderModel {
  final String id;
  final String tenantId;
  final String supplierId;
  final String destinationLocationId;
  final String referenceNumber;
  final String status;
  final DateTime? orderedAt;
  final DateTime? expectedAt;
  final String? notes;
  final String? createdBy;
  final String? approvedBy;
  final DateTime? approvedAt;
  final DateTime? createdAt;
  final DateTime? updatedAt;
  final SupplierModel? supplier;
  final String? destinationLocationName;
  final List<PurchaseOrderItemModel> items;

  const PurchaseOrderModel({
    required this.id,
    required this.tenantId,
    required this.supplierId,
    required this.destinationLocationId,
    required this.referenceNumber,
    required this.status,
    this.orderedAt,
    this.expectedAt,
    this.notes,
    this.createdBy,
    this.approvedBy,
    this.approvedAt,
    this.createdAt,
    this.updatedAt,
    this.supplier,
    this.destinationLocationName,
    this.items = const [],
  });

  bool get isDraft => status == 'draft';
  bool get isSubmitted => status == 'submitted';
  bool get isApproved => status == 'approved';
  bool get isPartiallyReceived => status == 'partially_received';
  bool get isReceived => status == 'received';
  bool get isCancelled => status == 'cancelled';

  bool get canReceive => isApproved || isPartiallyReceived;

  double get totalOrderedQuantity =>
      items.fold(0.0, (acc, item) => acc + item.orderedQuantity);

  double get totalReceivedQuantity =>
      items.fold(0.0, (acc, item) => acc + item.receivedQuantity);

  double get totalRemainingQuantity =>
      items.fold(0.0, (acc, item) => acc + item.remainingQuantity);

  int get totalCostMinor => items.fold(
    0,
    (acc, item) => acc + (item.unitCostMinor * item.orderedQuantity).round(),
  );

  String get formattedTotalCost {
    if (totalCostMinor <= 0) {
      return 'Biaya Belum Ditentukan';
    }
    final formatted = totalCostMinor.toString().replaceAllMapped(
      RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'),
      (Match m) => '${m[1]}.',
    );
    return 'Rp $formatted';
  }

  factory PurchaseOrderModel.fromJson(Map<String, dynamic> json) {
    final supplierData = json['supplier'] as Map<String, dynamic>?;
    final locationData = json['destination_location'] as Map<String, dynamic>?;
    final rawItems = json['items'] as List<dynamic>? ?? [];

    return PurchaseOrderModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      supplierId: json['supplier_id'] as String? ?? '',
      destinationLocationId: json['destination_location_id'] as String? ?? '',
      referenceNumber: json['reference_number'] as String? ?? '',
      status: json['status'] as String? ?? 'draft',
      orderedAt: json['ordered_at'] != null
          ? DateTime.tryParse(json['ordered_at'] as String)
          : null,
      expectedAt: json['expected_at'] != null
          ? DateTime.tryParse(json['expected_at'] as String)
          : null,
      notes: json['notes'] as String?,
      createdBy: json['created_by'] as String?,
      approvedBy: json['approved_by'] as String?,
      approvedAt: json['approved_at'] != null
          ? DateTime.tryParse(json['approved_at'] as String)
          : null,
      createdAt: json['created_at'] != null
          ? DateTime.tryParse(json['created_at'] as String)
          : null,
      updatedAt: json['updated_at'] != null
          ? DateTime.tryParse(json['updated_at'] as String)
          : null,
      supplier: supplierData != null
          ? SupplierModel.fromJson(supplierData)
          : null,
      destinationLocationName: locationData?['name'] as String?,
      items: rawItems
          .map(
            (i) => PurchaseOrderItemModel.fromJson(i as Map<String, dynamic>),
          )
          .toList(),
    );
  }
}

class PurchaseReceiptItemModel {
  final String id;
  final String tenantId;
  final String purchaseReceiptId;
  final String purchaseOrderItemId;
  final String inventoryItemId;
  final String? inventoryItemName;
  final String? inventoryItemSku;
  final double quantity;
  final int unitCostMinor;
  final String? notes;

  const PurchaseReceiptItemModel({
    required this.id,
    required this.tenantId,
    required this.purchaseReceiptId,
    required this.purchaseOrderItemId,
    required this.inventoryItemId,
    this.inventoryItemName,
    this.inventoryItemSku,
    required this.quantity,
    required this.unitCostMinor,
    this.notes,
  });

  String get formattedUnitCost {
    if (unitCostMinor <= 0) {
      return 'Biaya Belum Ditentukan';
    }
    final formatted = unitCostMinor.toString().replaceAllMapped(
      RegExp(r'(\d{1,3})(?=(\d{3})+(?!\d))'),
      (Match m) => '${m[1]}.',
    );
    return 'Rp $formatted';
  }

  factory PurchaseReceiptItemModel.fromJson(Map<String, dynamic> json) {
    final itemData = json['inventory_item'] as Map<String, dynamic>?;

    return PurchaseReceiptItemModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      purchaseReceiptId: json['purchase_receipt_id'] as String? ?? '',
      purchaseOrderItemId: json['purchase_order_item_id'] as String? ?? '',
      inventoryItemId: json['inventory_item_id'] as String? ?? '',
      inventoryItemName: itemData?['name'] as String?,
      inventoryItemSku: itemData?['sku'] as String?,
      quantity: double.tryParse(json['quantity']?.toString() ?? '0') ?? 0.0,
      unitCostMinor: (json['unit_cost_minor'] as num?)?.toInt() ?? 0,
      notes: json['notes'] as String?,
    );
  }
}

class PurchaseReceiptModel {
  final String id;
  final String tenantId;
  final String purchaseOrderId;
  final String destinationLocationId;
  final String referenceNumber;
  final String status;
  final DateTime? receivedAt;
  final String? receivedBy;
  final String? notes;
  final DateTime? createdAt;
  final DateTime? updatedAt;
  final String? destinationLocationName;
  final String? purchaseOrderReference;
  final List<PurchaseReceiptItemModel> items;

  const PurchaseReceiptModel({
    required this.id,
    required this.tenantId,
    required this.purchaseOrderId,
    required this.destinationLocationId,
    required this.referenceNumber,
    required this.status,
    this.receivedAt,
    this.receivedBy,
    this.notes,
    this.createdAt,
    this.updatedAt,
    this.destinationLocationName,
    this.purchaseOrderReference,
    this.items = const [],
  });

  bool get isDraft => status == 'draft';
  bool get isPosted => status == 'posted';
  bool get isCancelled => status == 'cancelled';

  double get totalReceivedQuantity =>
      items.fold(0.0, (acc, item) => acc + item.quantity);

  factory PurchaseReceiptModel.fromJson(Map<String, dynamic> json) {
    final locationData = json['destination_location'] as Map<String, dynamic>?;
    final poData = json['purchase_order'] as Map<String, dynamic>?;
    final rawItems = json['items'] as List<dynamic>? ?? [];

    return PurchaseReceiptModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      purchaseOrderId: json['purchase_order_id'] as String? ?? '',
      destinationLocationId: json['destination_location_id'] as String? ?? '',
      referenceNumber: json['reference_number'] as String? ?? '',
      status: json['status'] as String? ?? 'draft',
      receivedAt: json['received_at'] != null
          ? DateTime.tryParse(json['received_at'] as String)
          : null,
      receivedBy: json['received_by'] as String?,
      notes: json['notes'] as String?,
      createdAt: json['created_at'] != null
          ? DateTime.tryParse(json['created_at'] as String)
          : null,
      updatedAt: json['updated_at'] != null
          ? DateTime.tryParse(json['updated_at'] as String)
          : null,
      destinationLocationName: locationData?['name'] as String?,
      purchaseOrderReference: poData?['reference_number'] as String?,
      items: rawItems
          .map(
            (i) => PurchaseReceiptItemModel.fromJson(i as Map<String, dynamic>),
          )
          .toList(),
    );
  }
}
