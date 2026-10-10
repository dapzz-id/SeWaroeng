import 'category_model.dart';

class BarcodeModel {
  final String id;
  final String tenantId;
  final String variantId;
  final String barcode;
  final String type;

  const BarcodeModel({
    required this.id,
    required this.tenantId,
    required this.variantId,
    required this.barcode,
    this.type = 'standard',
  });

  factory BarcodeModel.fromJson(Map<String, dynamic> json) {
    return BarcodeModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      variantId: json['variant_id'] as String? ?? '',
      barcode: json['barcode'] as String? ?? '',
      type: json['type'] as String? ?? 'standard',
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'variant_id': variantId,
      'barcode': barcode,
      'type': type,
    };
  }
}

class PriceModel {
  final String id;
  final String tenantId;
  final String variantId;
  final String? outletId;
  final int amount;
  final String currency;
  final DateTime? effectiveFrom;
  final DateTime? effectiveUntil;
  final bool isActive;

  const PriceModel({
    required this.id,
    required this.tenantId,
    required this.variantId,
    this.outletId,
    required this.amount,
    this.currency = 'IDR',
    this.effectiveFrom,
    this.effectiveUntil,
    this.isActive = true,
  });

  String get formattedPrice {
    final buffer = StringBuffer('Rp ');
    final str = amount.toString();
    final len = str.length;
    for (int i = 0; i < len; i++) {
      if (i > 0 && (len - i) % 3 == 0) {
        buffer.write('.');
      }
      buffer.write(str[i]);
    }
    return buffer.toString();
  }

  factory PriceModel.fromJson(Map<String, dynamic> json) {
    return PriceModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      variantId: json['variant_id'] as String? ?? '',
      outletId: json['outlet_id'] as String?,
      amount: (json['amount'] as num?)?.toInt() ?? 0,
      currency: json['currency'] as String? ?? 'IDR',
      effectiveFrom: json['effective_from'] != null
          ? DateTime.tryParse(json['effective_from'] as String)
          : null,
      effectiveUntil: json['effective_until'] != null
          ? DateTime.tryParse(json['effective_until'] as String)
          : null,
      isActive: json['is_active'] as bool? ?? true,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'variant_id': variantId,
      'outlet_id': outletId,
      'amount': amount,
      'currency': currency,
      'effective_from': effectiveFrom?.toIso8601String(),
      'effective_until': effectiveUntil?.toIso8601String(),
      'is_active': isActive,
    };
  }
}

class ProductOutletAvailabilityModel {
  final String id;
  final String tenantId;
  final String productId;
  final String outletId;
  final bool isAvailable;

  const ProductOutletAvailabilityModel({
    required this.id,
    required this.tenantId,
    required this.productId,
    required this.outletId,
    required this.isAvailable,
  });

  factory ProductOutletAvailabilityModel.fromJson(Map<String, dynamic> json) {
    return ProductOutletAvailabilityModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      productId: json['product_id'] as String? ?? '',
      outletId: json['outlet_id'] as String? ?? '',
      isAvailable: json['is_available'] as bool? ?? true,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'product_id': productId,
      'outlet_id': outletId,
      'is_available': isAvailable,
    };
  }
}

class ProductVariantModel {
  final String id;
  final String tenantId;
  final String productId;
  final String name;
  final String? sku;
  final bool isDefault;
  final String status;
  final List<PriceModel> prices;
  final List<BarcodeModel> barcodes;
  final PriceModel? activePrice;

  const ProductVariantModel({
    required this.id,
    required this.tenantId,
    required this.productId,
    required this.name,
    this.sku,
    this.isDefault = false,
    this.status = 'active',
    this.prices = const [],
    this.barcodes = const [],
    this.activePrice,
  });

  factory ProductVariantModel.fromJson(Map<String, dynamic> json) {
    PriceModel? resolvedPrice;
    if (json['price'] != null) {
      resolvedPrice = PriceModel.fromJson(
        json['price'] as Map<String, dynamic>,
      );
    }

    return ProductVariantModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      productId: json['product_id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      sku: json['sku'] as String?,
      isDefault: json['is_default'] as bool? ?? false,
      status: json['status'] as String? ?? 'active',
      prices:
          (json['prices'] as List<dynamic>?)
              ?.map((p) => PriceModel.fromJson(p as Map<String, dynamic>))
              .toList() ??
          [],
      barcodes:
          (json['barcodes'] as List<dynamic>?)
              ?.map((b) => BarcodeModel.fromJson(b as Map<String, dynamic>))
              .toList() ??
          [],
      activePrice: resolvedPrice,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'product_id': productId,
      'name': name,
      'sku': sku,
      'is_default': isDefault,
      'status': status,
      'prices': prices.map((p) => p.toJson()).toList(),
      'barcodes': barcodes.map((b) => b.toJson()).toList(),
    };
  }
}

class ProductModel {
  final String id;
  final String tenantId;
  final String? categoryId;
  final String name;
  final String slug;
  final String? description;
  final String productType;
  final String status;
  final CategoryModel? category;
  final List<ProductVariantModel> variants;
  final List<ProductOutletAvailabilityModel> availabilities;
  final List<dynamic> modifierGroups;
  final bool isAvailable;
  final PriceModel? displayPrice;

  const ProductModel({
    required this.id,
    required this.tenantId,
    this.categoryId,
    required this.name,
    required this.slug,
    this.description,
    this.productType = 'product',
    this.status = 'active',
    this.category,
    this.variants = const [],
    this.availabilities = const [],
    this.modifierGroups = const [],
    this.isAvailable = true,
    this.displayPrice,
  });

  bool get isActive => status == 'active';
  bool get isStandardVariant => variants.length <= 1;

  ProductVariantModel? get defaultVariant {
    try {
      return variants.firstWhere((v) => v.isDefault);
    } catch (_) {
      return variants.isNotEmpty ? variants.first : null;
    }
  }

  String? get displaySku => defaultVariant?.sku;

  String get formattedPrice {
    if (displayPrice != null) {
      return displayPrice!.formattedPrice;
    }
    if (defaultVariant?.activePrice != null) {
      return defaultVariant!.activePrice!.formattedPrice;
    }
    if (defaultVariant != null && defaultVariant!.prices.isNotEmpty) {
      return defaultVariant!.prices.first.formattedPrice;
    }
    return 'Belum diatur';
  }

  bool get hasPrice =>
      displayPrice != null ||
      defaultVariant?.activePrice != null ||
      (defaultVariant != null && defaultVariant!.prices.isNotEmpty);

  factory ProductModel.fromJson(Map<String, dynamic> json) {
    PriceModel? resolvedPrice;
    if (json['price'] != null) {
      resolvedPrice = PriceModel.fromJson(
        json['price'] as Map<String, dynamic>,
      );
    }

    return ProductModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      categoryId: json['category_id'] as String?,
      name: json['name'] as String? ?? '',
      slug: json['slug'] as String? ?? '',
      description: json['description'] as String?,
      productType: json['product_type'] as String? ?? 'product',
      status: json['status'] as String? ?? 'active',
      category: json['category'] != null
          ? CategoryModel.fromJson(json['category'] as Map<String, dynamic>)
          : null,
      variants:
          (json['variants'] as List<dynamic>?)
              ?.map(
                (v) => ProductVariantModel.fromJson(v as Map<String, dynamic>),
              )
              .toList() ??
          [],
      availabilities:
          (json['availabilities'] as List<dynamic>?)
              ?.map(
                (a) => ProductOutletAvailabilityModel.fromJson(
                  a as Map<String, dynamic>,
                ),
              )
              .toList() ??
          [],
      modifierGroups: (json['modifier_groups'] as List<dynamic>?) ?? [],
      isAvailable: json['is_available'] as bool? ?? true,
      displayPrice: resolvedPrice,
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'category_id': categoryId,
      'name': name,
      'slug': slug,
      'description': description,
      'product_type': productType,
      'status': status,
      'variants': variants.map((v) => v.toJson()).toList(),
      'is_available': isAvailable,
    };
  }
}
