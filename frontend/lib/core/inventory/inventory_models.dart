class InventoryLocationModel {
  final String id;
  final String tenantId;
  final String? outletId;
  final String? outletName;
  final String name;
  final String code;
  final String type;
  final bool isActive;
  final DateTime? createdAt;

  const InventoryLocationModel({
    required this.id,
    required this.tenantId,
    this.outletId,
    this.outletName,
    required this.name,
    required this.code,
    this.type = 'outlet',
    this.isActive = true,
    this.createdAt,
  });

  String get typeLabel {
    switch (type) {
      case 'outlet':
        return 'Outlet';
      case 'warehouse':
        return 'Gudang';
      case 'kitchen':
        return 'Dapur';
      case 'storage':
        return 'Penyimpanan';
      case 'central_warehouse':
        return 'Gudang Pusat';
      default:
        return 'Lokasi';
    }
  }

  factory InventoryLocationModel.fromJson(Map<String, dynamic> json) {
    return InventoryLocationModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      outletId: json['outlet_id'] as String?,
      outletName: json['outlet_name'] as String?,
      name: json['name'] as String? ?? '',
      code: json['code'] as String? ?? '',
      type: json['type'] as String? ?? 'outlet',
      isActive: json['is_active'] as bool? ?? true,
      createdAt: json['created_at'] != null
          ? DateTime.tryParse(json['created_at'] as String)
          : null,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'outlet_id': outletId,
      'name': name,
      'code': code,
      'type': type,
      'is_active': isActive,
    };
  }
}

class InventoryItemLocationModel {
  final String locationId;
  final String? locationName;
  final String? locationCode;
  final String? locationType;
  final double onHand;
  final double reserved;
  final double available;
  final double reorderLevel;
  final bool isLowStock;

  const InventoryItemLocationModel({
    required this.locationId,
    this.locationName,
    this.locationCode,
    this.locationType,
    required this.onHand,
    required this.reserved,
    required this.available,
    required this.reorderLevel,
    required this.isLowStock,
  });

  factory InventoryItemLocationModel.fromJson(Map<String, dynamic> json) {
    final onHand = double.tryParse(json['on_hand']?.toString() ?? '0') ?? 0.0;
    final reserved =
        double.tryParse(json['reserved']?.toString() ?? '0') ?? 0.0;
    final available =
        double.tryParse(json['available']?.toString() ?? '0') ??
        (onHand - reserved);
    final reorderLevel =
        double.tryParse(json['reorder_level']?.toString() ?? '0') ?? 0.0;

    return InventoryItemLocationModel(
      locationId: json['location_id'] as String? ?? '',
      locationName: json['location_name'] as String?,
      locationCode: json['location_code'] as String?,
      locationType: json['location_type'] as String?,
      onHand: onHand,
      reserved: reserved,
      available: available,
      reorderLevel: reorderLevel,
      isLowStock: json['is_low_stock'] as bool? ?? (available <= reorderLevel),
    );
  }
}

class InventoryItemModel {
  final String id;
  final String tenantId;
  final String? productVariantId;
  final String? variantName;
  final String? productName;
  final String? sku;
  final String name;
  final String itemType;
  final String baseUnit;
  final String status;
  final bool allowNegativeStock;
  final double totalOnHand;
  final double totalReserved;
  final double totalAvailable;
  final InventoryItemLocationModel? locationBalance;
  final int locationsCount;
  final List<InventoryItemLocationModel> locationBalances;

  const InventoryItemModel({
    required this.id,
    required this.tenantId,
    this.productVariantId,
    this.variantName,
    this.productName,
    this.sku,
    required this.name,
    this.itemType = 'sellable',
    this.baseUnit = 'piece',
    this.status = 'active',
    this.allowNegativeStock = false,
    this.totalOnHand = 0.0,
    this.totalReserved = 0.0,
    this.totalAvailable = 0.0,
    this.locationBalance,
    this.locationsCount = 0,
    this.locationBalances = const [],
  });

  bool get isActive => status == 'active';
  bool get isNegative => totalOnHand < 0 || (locationBalance?.onHand ?? 0) < 0;
  bool get isOutOfStock => locationBalance != null
      ? locationBalance!.available <= 0
      : totalAvailable <= 0;
  bool get isLowStock =>
      locationBalance?.isLowStock ??
      (totalAvailable <= (locationBalance?.reorderLevel ?? 0));

  String get effectiveOnHand => locationBalance != null
      ? locationBalance!.onHand.toStringAsFixed(2)
      : totalOnHand.toStringAsFixed(2);

  String get effectiveAvailable => locationBalance != null
      ? locationBalance!.available.toStringAsFixed(2)
      : totalAvailable.toStringAsFixed(2);

  String get effectiveReserved => locationBalance != null
      ? locationBalance!.reserved.toStringAsFixed(2)
      : totalReserved.toStringAsFixed(2);

  factory InventoryItemModel.fromJson(Map<String, dynamic> json) {
    InventoryItemLocationModel? locBal;
    if (json['location_balance'] != null) {
      locBal = InventoryItemLocationModel.fromJson(
        json['location_balance'] as Map<String, dynamic>,
      );
    }

    final balances =
        (json['location_balances'] as List<dynamic>?)
            ?.map(
              (b) => InventoryItemLocationModel.fromJson(
                b as Map<String, dynamic>,
              ),
            )
            .toList() ??
        const [];

    return InventoryItemModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      productVariantId: json['product_variant_id'] as String?,
      variantName: json['variant_name'] as String?,
      productName: json['product_name'] as String?,
      sku: json['sku'] as String?,
      name: json['name'] as String? ?? '',
      itemType: json['item_type'] as String? ?? 'sellable',
      baseUnit: json['base_unit'] as String? ?? 'piece',
      status: json['status'] as String? ?? 'active',
      allowNegativeStock: json['allow_negative_stock'] as bool? ?? false,
      totalOnHand:
          double.tryParse(json['total_on_hand']?.toString() ?? '0') ?? 0.0,
      totalReserved:
          double.tryParse(json['total_reserved']?.toString() ?? '0') ?? 0.0,
      totalAvailable:
          double.tryParse(json['total_available']?.toString() ?? '0') ?? 0.0,
      locationBalance: locBal,
      locationsCount: json['locations_count'] as int? ?? balances.length,
      locationBalances: balances,
    );
  }
}

class InventoryMovementModel {
  final String id;
  final String movementNumber;
  final String locationId;
  final String? locationName;
  final String movementType;
  final double quantityDelta;
  final String unit;
  final double beforeOnHand;
  final double afterOnHand;
  final String? reason;
  final String? note;
  final DateTime? occurredAt;
  final String? creatorName;

  const InventoryMovementModel({
    required this.id,
    required this.movementNumber,
    required this.locationId,
    this.locationName,
    required this.movementType,
    required this.quantityDelta,
    required this.unit,
    required this.beforeOnHand,
    required this.afterOnHand,
    this.reason,
    this.note,
    this.occurredAt,
    this.creatorName,
  });

  bool get isIncoming => quantityDelta > 0;

  String get typeLabel {
    switch (movementType) {
      case 'opening_balance':
        return 'Saldo Awal';
      case 'adjustment_in':
        return 'Penyesuaian Masuk';
      case 'adjustment_out':
        return 'Penyesuaian Keluar';
      case 'stock_opname':
        return 'Stock Opname';
      case 'transfer_in':
        return 'Transfer Masuk';
      case 'transfer_out':
        return 'Transfer Keluar';
      case 'waste':
        return 'Terbuang / Rusak';
      case 'expired':
        return 'Kedaluwarsa';
      case 'reserved':
        return 'Dicadangkan';
      case 'released':
        return 'Dilepas';
      default:
        return movementType;
    }
  }

  factory InventoryMovementModel.fromJson(Map<String, dynamic> json) {
    return InventoryMovementModel(
      id: json['id'] as String,
      movementNumber: json['movement_number'] as String? ?? '',
      locationId: json['location_id'] as String? ?? '',
      locationName: json['location_name'] as String?,
      movementType: json['movement_type'] as String? ?? 'adjustment',
      quantityDelta:
          double.tryParse(json['quantity_delta']?.toString() ?? '0') ?? 0.0,
      unit: json['unit'] as String? ?? 'piece',
      beforeOnHand:
          double.tryParse(json['before_on_hand']?.toString() ?? '0') ?? 0.0,
      afterOnHand:
          double.tryParse(json['after_on_hand']?.toString() ?? '0') ?? 0.0,
      reason: json['reason'] as String?,
      note: json['note'] as String?,
      occurredAt: json['occurred_at'] != null
          ? DateTime.tryParse(json['occurred_at'] as String)
          : null,
      creatorName: json['creator_name'] as String?,
    );
  }
}

class StockOpnameItemModel {
  final String id;
  final String inventoryItemId;
  final String? itemName;
  final String? sku;
  final String? unit;
  final double systemQuantity;
  final double physicalQuantity;
  final double difference;
  final String? reason;
  final String? notes;

  const StockOpnameItemModel({
    required this.id,
    required this.inventoryItemId,
    this.itemName,
    this.sku,
    this.unit,
    required this.systemQuantity,
    required this.physicalQuantity,
    required this.difference,
    this.reason,
    this.notes,
  });

  factory StockOpnameItemModel.fromJson(Map<String, dynamic> json) {
    return StockOpnameItemModel(
      id: json['id'] as String,
      inventoryItemId: json['inventory_item_id'] as String? ?? '',
      itemName: json['item_name'] as String?,
      sku: json['sku'] as String?,
      unit: json['unit'] as String?,
      systemQuantity:
          double.tryParse(json['system_quantity']?.toString() ?? '0') ?? 0.0,
      physicalQuantity:
          double.tryParse(json['physical_quantity']?.toString() ?? '0') ?? 0.0,
      difference: double.tryParse(json['difference']?.toString() ?? '0') ?? 0.0,
      reason: json['reason'] as String?,
      notes: json['notes'] as String?,
    );
  }
}

class StockOpnameModel {
  final String id;
  final String opnameNumber;
  final String locationId;
  final String? locationName;
  final String status;
  final String? notes;
  final String? countedBy;
  final String? approvedBy;
  final DateTime? approvedAt;
  final DateTime? createdAt;
  final int itemsCount;
  final List<StockOpnameItemModel> items;

  const StockOpnameModel({
    required this.id,
    required this.opnameNumber,
    required this.locationId,
    this.locationName,
    this.status = 'draft',
    this.notes,
    this.countedBy,
    this.approvedBy,
    this.approvedAt,
    this.createdAt,
    this.itemsCount = 0,
    this.items = const [],
  });

  bool get isApproved => status == 'approved';
  bool get isDraft => status == 'draft';

  factory StockOpnameModel.fromJson(Map<String, dynamic> json) {
    final itemList =
        (json['items'] as List<dynamic>?)
            ?.map(
              (i) => StockOpnameItemModel.fromJson(i as Map<String, dynamic>),
            )
            .toList() ??
        const [];

    return StockOpnameModel(
      id: json['id'] as String,
      opnameNumber: json['opname_number'] as String? ?? '',
      locationId: json['location_id'] as String? ?? '',
      locationName: json['location_name'] as String?,
      status: json['status'] as String? ?? 'draft',
      notes: json['notes'] as String?,
      countedBy: json['counted_by'] as String?,
      approvedBy: json['approved_by'] as String?,
      approvedAt: json['approved_at'] != null
          ? DateTime.tryParse(json['approved_at'] as String)
          : null,
      createdAt: json['created_at'] != null
          ? DateTime.tryParse(json['created_at'] as String)
          : null,
      itemsCount: json['items_count'] as int? ?? itemList.length,
      items: itemList,
    );
  }
}

class StockTransferItemModel {
  final String id;
  final String inventoryItemId;
  final String? itemName;
  final String? sku;
  final String? unit;
  final double quantity;

  const StockTransferItemModel({
    required this.id,
    required this.inventoryItemId,
    this.itemName,
    this.sku,
    this.unit,
    required this.quantity,
  });

  factory StockTransferItemModel.fromJson(Map<String, dynamic> json) {
    return StockTransferItemModel(
      id: json['id'] as String,
      inventoryItemId: json['inventory_item_id'] as String? ?? '',
      itemName: json['item_name'] as String?,
      sku: json['sku'] as String?,
      unit: json['unit'] as String?,
      quantity: double.tryParse(json['quantity']?.toString() ?? '0') ?? 0.0,
    );
  }
}

class StockTransferModel {
  final String id;
  final String transferNumber;
  final String sourceLocationId;
  final String? sourceLocationName;
  final String destinationLocationId;
  final String? destinationLocationName;
  final String status;
  final String? notes;
  final String? requestedBy;
  final String? approvedBy;
  final DateTime? dispatchedAt;
  final String? receivedBy;
  final DateTime? receivedAt;
  final DateTime? createdAt;
  final int itemsCount;
  final List<StockTransferItemModel> items;

  const StockTransferModel({
    required this.id,
    required this.transferNumber,
    required this.sourceLocationId,
    this.sourceLocationName,
    required this.destinationLocationId,
    this.destinationLocationName,
    this.status = 'draft',
    this.notes,
    this.requestedBy,
    this.approvedBy,
    this.dispatchedAt,
    this.receivedBy,
    this.receivedAt,
    this.createdAt,
    this.itemsCount = 0,
    this.items = const [],
  });

  bool get isPending => status == 'pending';
  bool get isInTransit => status == 'in_transit';
  bool get isReceived => status == 'received';

  factory StockTransferModel.fromJson(Map<String, dynamic> json) {
    final itemList =
        (json['items'] as List<dynamic>?)
            ?.map(
              (i) => StockTransferItemModel.fromJson(i as Map<String, dynamic>),
            )
            .toList() ??
        const [];

    return StockTransferModel(
      id: json['id'] as String,
      transferNumber: json['transfer_number'] as String? ?? '',
      sourceLocationId: json['source_location_id'] as String? ?? '',
      sourceLocationName: json['source_location_name'] as String?,
      destinationLocationId: json['destination_location_id'] as String? ?? '',
      destinationLocationName: json['destination_location_name'] as String?,
      status: json['status'] as String? ?? 'draft',
      notes: json['notes'] as String?,
      requestedBy: json['requested_by'] as String?,
      approvedBy: json['approved_by'] as String?,
      dispatchedAt: json['dispatched_at'] != null
          ? DateTime.tryParse(json['dispatched_at'] as String)
          : null,
      receivedBy: json['received_by'] as String?,
      receivedAt: json['received_at'] != null
          ? DateTime.tryParse(json['received_at'] as String)
          : null,
      createdAt: json['created_at'] != null
          ? DateTime.tryParse(json['created_at'] as String)
          : null,
      itemsCount: json['items_count'] as int? ?? itemList.length,
      items: itemList,
    );
  }
}
