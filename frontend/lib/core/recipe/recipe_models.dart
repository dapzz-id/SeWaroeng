class RecipeComponentModel {
  final String id;
  final String inventoryItemId;
  final String itemName;
  final String? itemSku;
  final String baseUnit;
  final double quantity;
  final String unit;
  final double wasteFactor;

  const RecipeComponentModel({
    required this.id,
    required this.inventoryItemId,
    required this.itemName,
    this.itemSku,
    required this.baseUnit,
    required this.quantity,
    required this.unit,
    this.wasteFactor = 0.0,
  });

  factory RecipeComponentModel.fromJson(Map<String, dynamic> json) {
    return RecipeComponentModel(
      id: json['id'] as String? ?? '',
      inventoryItemId: json['inventory_item_id'] as String? ?? '',
      itemName: json['item_name'] as String? ?? 'Bahan',
      itemSku: json['item_sku'] as String?,
      baseUnit: json['base_unit'] as String? ?? 'pcs',
      quantity: double.tryParse(json['quantity']?.toString() ?? '1') ?? 1.0,
      unit: json['unit'] as String? ?? 'pcs',
      wasteFactor: double.tryParse(json['waste_factor']?.toString() ?? '0') ?? 0.0,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'inventory_item_id': inventoryItemId,
        'quantity': quantity,
        'unit': unit,
        'waste_factor': wasteFactor,
      };
}

class RecipeVersionModel {
  final String id;
  final int versionNumber;
  final String status; // 'draft', 'published', 'archived'
  final double yieldQuantity;
  final String yieldUnit;
  final List<RecipeComponentModel> components;

  const RecipeVersionModel({
    required this.id,
    required this.versionNumber,
    required this.status,
    required this.yieldQuantity,
    required this.yieldUnit,
    this.components = const [],
  });

  factory RecipeVersionModel.fromJson(Map<String, dynamic> json) {
    return RecipeVersionModel(
      id: json['id'] as String? ?? '',
      versionNumber: json['version_number'] as int? ?? 1,
      status: json['status'] as String? ?? 'draft',
      yieldQuantity: double.tryParse(json['yield_quantity']?.toString() ?? '1') ?? 1.0,
      yieldUnit: json['yield_unit'] as String? ?? 'portion',
      components: (json['components'] as List<dynamic>?)
              ?.map((e) => RecipeComponentModel.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
    );
  }
}

class RecipeModel {
  final String id;
  final String tenantId;
  final String? productVariantId;
  final String? productName;
  final String name;
  final String code;
  final String status; // 'active', 'archived'
  final List<RecipeVersionModel> versions;

  const RecipeModel({
    required this.id,
    required this.tenantId,
    this.productVariantId,
    this.productName,
    required this.name,
    required this.code,
    required this.status,
    this.versions = const [],
  });

  factory RecipeModel.fromJson(Map<String, dynamic> json) {
    return RecipeModel(
      id: json['id'] as String? ?? '',
      tenantId: json['tenant_id'] as String? ?? '',
      productVariantId: json['product_variant_id'] as String?,
      productName: json['product_name'] as String?,
      name: json['name'] as String? ?? '',
      code: json['code'] as String? ?? '',
      status: json['status'] as String? ?? 'active',
      versions: (json['versions'] as List<dynamic>?)
              ?.map((e) => RecipeVersionModel.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'tenant_id': tenantId,
        'product_variant_id': productVariantId,
        'name': name,
        'code': code,
        'status': status,
      };
}
