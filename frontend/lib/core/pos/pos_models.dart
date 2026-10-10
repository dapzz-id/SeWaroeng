import 'package:flutter/foundation.dart';

enum OfflineSyncStatus {
  localSaved,
  syncPending,
  syncing,
  synced,
  needsReview,
  failed;

  String get label {
    switch (this) {
      case OfflineSyncStatus.localSaved:
        return 'Tersimpan Lokal';
      case OfflineSyncStatus.syncPending:
        return 'Menunggu Sinkronisasi';
      case OfflineSyncStatus.syncing:
        return 'Sedang Sinkronisasi';
      case OfflineSyncStatus.synced:
        return 'Tersinkronisasi';
      case OfflineSyncStatus.needsReview:
        return 'Perlu Ditinjau';
      case OfflineSyncStatus.failed:
        return 'Gagal Sinkronisasi';
    }
  }

  String toValue() {
    switch (this) {
      case OfflineSyncStatus.localSaved:
        return 'local_saved';
      case OfflineSyncStatus.syncPending:
        return 'pending';
      case OfflineSyncStatus.syncing:
        return 'syncing';
      case OfflineSyncStatus.synced:
        return 'synced';
      case OfflineSyncStatus.needsReview:
        return 'blocked';
      case OfflineSyncStatus.failed:
        return 'failed';
    }
  }

  static OfflineSyncStatus fromValue(String? value) {
    switch (value) {
      case 'local_saved':
        return OfflineSyncStatus.localSaved;
      case 'pending':
        return OfflineSyncStatus.syncPending;
      case 'syncing':
      case 'uploading':
        return OfflineSyncStatus.syncing;
      case 'synced':
        return OfflineSyncStatus.synced;
      case 'blocked':
      case 'conflict':
      case 'requires_action':
        return OfflineSyncStatus.needsReview;
      case 'failed':
      default:
        return OfflineSyncStatus.failed;
    }
  }
}

@immutable
class PosCatalogCategory {
  final String id;
  final String name;
  final String slug;

  const PosCatalogCategory({
    required this.id,
    required this.name,
    required this.slug,
  });

  factory PosCatalogCategory.fromJson(Map<String, dynamic> json) {
    return PosCatalogCategory(
      id: json['id'] as String,
      name: json['name'] as String,
      slug: json['slug'] as String,
    );
  }
}

@immutable
class PosCatalogPrice {
  final String priceId;
  final int amount;
  final String currency;

  const PosCatalogPrice({
    required this.priceId,
    required this.amount,
    required this.currency,
  });

  factory PosCatalogPrice.fromJson(Map<String, dynamic> json) {
    return PosCatalogPrice(
      priceId: json['price_id'] as String,
      amount: (json['amount'] as num).toInt(),
      currency: (json['currency'] as String?) ?? 'IDR',
    );
  }
}

@immutable
class PosCatalogVariant {
  final String id;
  final String name;
  final String sku;
  final PosCatalogPrice? resolvedPrice;

  const PosCatalogVariant({
    required this.id,
    required this.name,
    required this.sku,
    this.resolvedPrice,
  });

  bool get hasPrice => resolvedPrice != null && resolvedPrice!.amount > 0;

  factory PosCatalogVariant.fromJson(Map<String, dynamic> json) {
    return PosCatalogVariant(
      id: json['id'] as String,
      name: json['name'] as String,
      sku: (json['sku'] as String?) ?? '',
      resolvedPrice: json['resolved_price'] != null
          ? PosCatalogPrice.fromJson(json['resolved_price'] as Map<String, dynamic>)
          : null,
    );
  }
}

@immutable
class PosCatalogModifier {
  final String id;
  final String name;
  final int priceAdjustment;

  const PosCatalogModifier({
    required this.id,
    required this.name,
    required this.priceAdjustment,
  });

  factory PosCatalogModifier.fromJson(Map<String, dynamic> json) {
    return PosCatalogModifier(
      id: json['id'] as String,
      name: json['name'] as String,
      priceAdjustment: (json['price_adjustment'] as num?)?.toInt() ?? 0,
    );
  }
}

@immutable
class PosCatalogModifierGroup {
  final String id;
  final String name;
  final String selectionType;
  final int minSelection;
  final int maxSelection;
  final bool isRequired;
  final List<PosCatalogModifier> modifiers;

  const PosCatalogModifierGroup({
    required this.id,
    required this.name,
    required this.selectionType,
    required this.minSelection,
    required this.maxSelection,
    required this.isRequired,
    required this.modifiers,
  });

  bool get isSingle => selectionType == 'single' || maxSelection == 1;

  factory PosCatalogModifierGroup.fromJson(Map<String, dynamic> json) {
    return PosCatalogModifierGroup(
      id: json['id'] as String,
      name: json['name'] as String,
      selectionType: (json['selection_type'] as String?) ?? 'multiple',
      minSelection: (json['min_selection'] as num?)?.toInt() ?? 0,
      maxSelection: (json['max_selection'] as num?)?.toInt() ?? 1,
      isRequired: (json['is_required'] as bool?) ?? false,
      modifiers: (json['modifiers'] as List<dynamic>?)
              ?.map((e) => PosCatalogModifier.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
    );
  }
}

@immutable
class PosCatalogProduct {
  final String id;
  final String name;
  final String? description;
  final String? categoryId;
  final String? categoryName;
  final List<PosCatalogVariant> variants;
  final List<PosCatalogModifierGroup> modifierGroups;

  const PosCatalogProduct({
    required this.id,
    required this.name,
    this.description,
    this.categoryId,
    this.categoryName,
    required this.variants,
    required this.modifierGroups,
  });

  bool get hasActivePrice => variants.any((v) => v.hasPrice);

  factory PosCatalogProduct.fromJson(Map<String, dynamic> json) {
    return PosCatalogProduct(
      id: json['id'] as String,
      name: json['name'] as String,
      description: json['description'] as String?,
      categoryId: json['category_id'] as String?,
      categoryName: json['category_name'] as String?,
      variants: (json['variants'] as List<dynamic>?)
              ?.map((e) => PosCatalogVariant.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
      modifierGroups: (json['modifier_groups'] as List<dynamic>?)
              ?.map((e) =>
                  PosCatalogModifierGroup.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
    );
  }
}

@immutable
class PosSaleItemModifier {
  final String id;
  final String? modifierId;
  final String modifierNameSnapshot;
  final String groupNameSnapshot;
  final double quantity;
  final int unitPriceMinor;
  final int unitPriceAdjustmentMinor;
  final int subtotalMinor;

  const PosSaleItemModifier({
    required this.id,
    this.modifierId,
    required this.modifierNameSnapshot,
    required this.groupNameSnapshot,
    required this.quantity,
    required this.unitPriceMinor,
    required this.unitPriceAdjustmentMinor,
    required this.subtotalMinor,
  });

  factory PosSaleItemModifier.fromJson(Map<String, dynamic> json) {
    return PosSaleItemModifier(
      id: json['id'] as String,
      modifierId: json['modifier_id'] as String?,
      modifierNameSnapshot: (json['modifier_name_snapshot'] as String?) ?? '',
      groupNameSnapshot: (json['group_name_snapshot'] as String?) ?? '',
      quantity: (json['quantity'] as num?)?.toDouble() ?? 1.0,
      unitPriceMinor: (json['unit_price_minor'] as num?)?.toInt() ?? 0,
      unitPriceAdjustmentMinor:
          (json['unit_price_adjustment_minor'] as num?)?.toInt() ?? 0,
      subtotalMinor: (json['subtotal_minor'] as num?)?.toInt() ?? 0,
    );
  }
}

@immutable
class PosSaleItem {
  final String id;
  final String saleId;
  final String productVariantId;
  final String productNameSnapshot;
  final String variantNameSnapshot;
  final double quantity;
  final int unitPriceMinor;
  final int subtotalMinor;
  final int discountMinor;
  final int totalMinor;
  final String? priceId;
  final String? recipeVersionId;
  final Map<String, dynamic>? recipeSnapshot;
  final String? notes;
  final List<PosSaleItemModifier> modifiers;

  const PosSaleItem({
    required this.id,
    required this.saleId,
    required this.productVariantId,
    required this.productNameSnapshot,
    required this.variantNameSnapshot,
    required this.quantity,
    required this.unitPriceMinor,
    required this.subtotalMinor,
    required this.discountMinor,
    required this.totalMinor,
    this.priceId,
    this.recipeVersionId,
    this.recipeSnapshot,
    this.notes,
    required this.modifiers,
  });

  factory PosSaleItem.fromJson(Map<String, dynamic> json) {
    return PosSaleItem(
      id: json['id'] as String,
      saleId: json['sale_id'] as String,
      productVariantId: json['product_variant_id'] as String,
      productNameSnapshot: (json['product_name_snapshot'] as String?) ?? '',
      variantNameSnapshot: (json['variant_name_snapshot'] as String?) ?? '',
      quantity: (json['quantity'] as num?)?.toDouble() ?? 1.0,
      unitPriceMinor: (json['unit_price_minor'] as num?)?.toInt() ?? 0,
      subtotalMinor: (json['subtotal_minor'] as num?)?.toInt() ?? 0,
      discountMinor: (json['discount_minor'] as num?)?.toInt() ?? 0,
      totalMinor: (json['total_minor'] as num?)?.toInt() ?? 0,
      priceId: json['price_id'] as String?,
      recipeVersionId: json['recipe_version_id'] as String?,
      recipeSnapshot: json['recipe_snapshot'] as Map<String, dynamic>?,
      notes: json['notes'] as String?,
      modifiers: (json['modifiers'] as List<dynamic>?)
              ?.map((e) => PosSaleItemModifier.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
    );
  }
}

@immutable
class PosSale {
  final String id;
  final String tenantId;
  final String outletId;
  final String referenceNumber;
  final String status;
  final DateTime? openedAt;
  final DateTime? finalizedAt;
  final DateTime? cancelledAt;
  final String? cashierUserId;
  final String? customerId;
  final int subtotalMinor;
  final int discountMinor;
  final int taxMinor;
  final int totalMinor;
  final String? customerNameSnapshot;
  final String? paymentMethod;
  final DateTime? syncedAt;
  final String? syncError;
  final String? notes;
  final List<PosSaleItem> items;
  final OfflineSyncStatus syncStatus;

  const PosSale({
    required this.id,
    required this.tenantId,
    required this.outletId,
    required this.referenceNumber,
    required this.status,
    this.openedAt,
    this.finalizedAt,
    this.cancelledAt,
    this.cashierUserId,
    this.customerId,
    this.customerNameSnapshot,
    this.paymentMethod,
    this.syncedAt,
    this.syncError,
    required this.subtotalMinor,
    required this.discountMinor,
    required this.taxMinor,
    required this.totalMinor,
    this.notes,
    required this.items,
    this.syncStatus = OfflineSyncStatus.synced,
  });

  bool get isOpen => status == 'open';
  bool get isFinalized => status == 'finalized';
  bool get isCancelled => status == 'cancelled';

  factory PosSale.fromJson(
    Map<String, dynamic> json, {
    OfflineSyncStatus syncStatus = OfflineSyncStatus.synced,
  }) {
    return PosSale(
      id: json['id'] as String,
      tenantId: (json['tenant_id'] as String?) ?? '',
      outletId: (json['outlet_id'] as String?) ?? '',
      referenceNumber: (json['reference_number'] as String?) ?? '',
      status: (json['status'] as String?) ?? 'open',
      openedAt: json['opened_at'] != null ? DateTime.tryParse(json['opened_at'] as String) : null,
      finalizedAt: json['finalized_at'] != null ? DateTime.tryParse(json['finalized_at'] as String) : null,
      cancelledAt: json['cancelled_at'] != null ? DateTime.tryParse(json['cancelled_at'] as String) : null,
      cashierUserId: json['cashier_user_id'] as String?,
      customerId: json['customer_id'] as String?,
      customerNameSnapshot: json['customer_name_snapshot'] as String?,
      paymentMethod: json['payment_method'] as String?,
      syncedAt: json['synced_at'] != null ? DateTime.tryParse(json['synced_at'] as String) : null,
      syncError: json['sync_error'] as String?,
      subtotalMinor: (json['subtotal_minor'] as num?)?.toInt() ?? 0,
      discountMinor: (json['discount_minor'] as num?)?.toInt() ?? 0,
      taxMinor: (json['tax_minor'] as num?)?.toInt() ?? 0,
      totalMinor: (json['total_minor'] as num?)?.toInt() ?? 0,
      notes: json['notes'] as String?,
      items: (json['items'] as List<dynamic>?)
              ?.map((e) => PosSaleItem.fromJson(e as Map<String, dynamic>))
              .toList() ??
          const [],
      syncStatus: syncStatus,
    );
  }
}

class PosCartItem {
  final String id;
  final PosCatalogProduct product;
  final PosCatalogVariant variant;
  int quantity;
  final List<PosCatalogModifier> selectedModifiers;
  String? notes;

  PosCartItem({
    required this.id,
    required this.product,
    required this.variant,
    this.quantity = 1,
    List<PosCatalogModifier>? selectedModifiers,
    this.notes,
  }) : selectedModifiers = selectedModifiers ?? [];

  int get unitPriceMinor => variant.resolvedPrice?.amount ?? 0;

  int get modifiersUnitPriceMinor =>
      selectedModifiers.fold(0, (sum, m) => sum + m.priceAdjustment);

  int get totalUnitPriceMinor => unitPriceMinor + modifiersUnitPriceMinor;

  int get subtotalMinor => totalUnitPriceMinor * quantity;

  Map<String, dynamic> toApiItem() {
    return {
      'product_variant_id': variant.id,
      'quantity': quantity,
      'notes': notes,
      'modifiers': selectedModifiers
          .map((m) => {
                'modifier_id': m.id,
                'quantity': 1, // 1 per item portion
              })
          .toList(),
    };
  }
}
