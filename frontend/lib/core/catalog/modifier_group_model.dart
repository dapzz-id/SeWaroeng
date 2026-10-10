class ModifierItemModel {
  final String id;
  final String tenantId;
  final String modifierGroupId;
  final String name;
  final String? sku;
  final int priceDelta;
  final int sortOrder;
  final String status;

  const ModifierItemModel({
    required this.id,
    required this.tenantId,
    required this.modifierGroupId,
    required this.name,
    this.sku,
    this.priceDelta = 0,
    this.sortOrder = 0,
    this.status = 'active',
  });

  bool get isActive => status == 'active';

  String get formattedPriceDelta {
    if (priceDelta == 0) return '+Rp 0';
    final sign = priceDelta > 0 ? '+Rp ' : '-Rp ';
    final absVal = priceDelta.abs();
    final buffer = StringBuffer(sign);
    final str = absVal.toString();
    final len = str.length;
    for (int i = 0; i < len; i++) {
      if (i > 0 && (len - i) % 3 == 0) {
        buffer.write('.');
      }
      buffer.write(str[i]);
    }
    return buffer.toString();
  }

  factory ModifierItemModel.fromJson(Map<String, dynamic> json) {
    return ModifierItemModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      modifierGroupId: json['modifier_group_id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      sku: json['sku'] as String?,
      priceDelta: (json['price_delta'] as num?)?.toInt() ?? 0,
      sortOrder: (json['sort_order'] as num?)?.toInt() ?? 0,
      status: json['status'] as String? ?? 'active',
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'modifier_group_id': modifierGroupId,
      'name': name,
      'sku': sku,
      'price_delta': priceDelta,
      'sort_order': sortOrder,
      'status': status,
    };
  }
}

class ModifierGroupModel {
  final String id;
  final String tenantId;
  final String name;
  final String selectionMode; // 'single', 'multiple'
  final int minSelection;
  final int maxSelection;
  final bool isRequired;
  final String status;
  final List<ModifierItemModel> modifiers;

  const ModifierGroupModel({
    required this.id,
    required this.tenantId,
    required this.name,
    this.selectionMode = 'single',
    this.minSelection = 0,
    this.maxSelection = 1,
    this.isRequired = false,
    this.status = 'active',
    this.modifiers = const [],
  });

  bool get isActive => status == 'active';
  bool get isSingleSelection => selectionMode == 'single';

  factory ModifierGroupModel.fromJson(Map<String, dynamic> json) {
    return ModifierGroupModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      selectionMode: json['selection_mode'] as String? ?? 'single',
      minSelection: (json['min_selection'] as num?)?.toInt() ?? 0,
      maxSelection: (json['max_selection'] as num?)?.toInt() ?? 1,
      isRequired: json['is_required'] as bool? ?? false,
      status: json['status'] as String? ?? 'active',
      modifiers:
          (json['modifiers'] as List<dynamic>?)
              ?.map(
                (m) => ModifierItemModel.fromJson(m as Map<String, dynamic>),
              )
              .toList() ??
          [],
    );
  }

  Map<String, dynamic> toJson() {
    return {
      'id': id,
      'tenant_id': tenantId,
      'name': name,
      'selection_mode': selectionMode,
      'min_selection': minSelection,
      'max_selection': maxSelection,
      'is_required': isRequired,
      'status': status,
      'modifiers': modifiers.map((m) => m.toJson()).toList(),
    };
  }
}
