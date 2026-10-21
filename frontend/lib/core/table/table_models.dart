class TableModel {
  final String id;
  final String tenantId;
  final String outletId;
  final String code;
  final String name;
  final int capacity;
  final String status; // 'available', 'occupied', 'reserved', 'inactive'
  final String? qrToken;
  final bool isActive;
  final Map<String, dynamic>? metadata;
  final Map<String, dynamic>? activeSession;
  final String? createdAt;

  const TableModel({
    required this.id,
    required this.tenantId,
    required this.outletId,
    required this.code,
    required this.name,
    this.capacity = 4,
    this.status = 'available',
    this.qrToken,
    this.isActive = true,
    this.metadata,
    this.activeSession,
    this.createdAt,
  });

  bool get isAvailable => status == 'available';
  bool get isOccupied => status == 'occupied';
  bool get isReserved => status == 'reserved';

  double get posX => (metadata?['x'] as num?)?.toDouble() ?? 50.0;
  double get posY => (metadata?['y'] as num?)?.toDouble() ?? 50.0;
  double get tableWidth => (metadata?['width'] as num?)?.toDouble() ?? 90.0;
  double get tableHeight => (metadata?['height'] as num?)?.toDouble() ?? 90.0;
  String get shape => metadata?['shape'] as String? ?? 'rect'; // 'rect', 'circle', 'square'
  String get zone => metadata?['zone'] as String? ?? 'indoor';

  TableModel copyWith({
    String? name,
    int? capacity,
    String? status,
    bool? isActive,
    double? x,
    double? y,
    double? width,
    double? height,
    String? shape,
    String? zone,
  }) {
    final newMeta = Map<String, dynamic>.from(metadata ?? {});
    if (x != null) newMeta['x'] = x;
    if (y != null) newMeta['y'] = y;
    if (width != null) newMeta['width'] = width;
    if (height != null) newMeta['height'] = height;
    if (shape != null) newMeta['shape'] = shape;
    if (zone != null) newMeta['zone'] = zone;

    return TableModel(
      id: id,
      tenantId: tenantId,
      outletId: outletId,
      code: code,
      name: name ?? this.name,
      capacity: capacity ?? this.capacity,
      status: status ?? this.status,
      qrToken: qrToken,
      isActive: isActive ?? this.isActive,
      metadata: newMeta,
      activeSession: activeSession,
      createdAt: createdAt,
    );
  }

  factory TableModel.fromJson(Map<String, dynamic> json) {
    return TableModel(
      id: json['id'] as String? ?? '',
      tenantId: json['tenant_id'] as String? ?? '',
      outletId: json['outlet_id'] as String? ?? '',
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      capacity: json['capacity'] as int? ?? 4,
      status: json['status'] as String? ?? 'available',
      qrToken: json['qr_token'] as String?,
      isActive: json['is_active'] as bool? ?? true,
      metadata: json['metadata'] as Map<String, dynamic>?,
      activeSession: json['active_session'] as Map<String, dynamic>?,
      createdAt: json['created_at'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'tenant_id': tenantId,
        'outlet_id': outletId,
        'code': code,
        'name': name,
        'capacity': capacity,
        'status': status,
        'qr_token': qrToken,
        'is_active': isActive,
        'metadata': metadata,
        'active_session': activeSession,
      };
}

class FloorPlanElement {
  final String id;
  final String type; // 'wall', 'zone', 'text'
  final String? label;
  final double? x;
  final double? y;
  final double? width;
  final double? height;
  final double? x1;
  final double? y1;
  final double? x2;
  final double? y2;
  final double? thickness;
  final String? color;
  final double? fontSize;

  const FloorPlanElement({
    required this.id,
    required this.type,
    this.label,
    this.x,
    this.y,
    this.width,
    this.height,
    this.x1,
    this.y1,
    this.x2,
    this.y2,
    this.thickness,
    this.color,
    this.fontSize,
  });

  FloorPlanElement copyWith({
    String? label,
    double? x,
    double? y,
    double? width,
    double? height,
    double? x1,
    double? y1,
    double? x2,
    double? y2,
    double? thickness,
    String? color,
    double? fontSize,
  }) {
    return FloorPlanElement(
      id: id,
      type: type,
      label: label ?? this.label,
      x: x ?? this.x,
      y: y ?? this.y,
      width: width ?? this.width,
      height: height ?? this.height,
      x1: x1 ?? this.x1,
      y1: y1 ?? this.y1,
      x2: x2 ?? this.x2,
      y2: y2 ?? this.y2,
      thickness: thickness ?? this.thickness,
      color: color ?? this.color,
      fontSize: fontSize ?? this.fontSize,
    );
  }

  factory FloorPlanElement.fromJson(Map<String, dynamic> json) {
    return FloorPlanElement(
      id: json['id'] as String? ?? '',
      type: json['type'] as String? ?? 'text',
      label: json['label'] as String?,
      x: (json['x'] as num?)?.toDouble(),
      y: (json['y'] as num?)?.toDouble(),
      width: (json['width'] as num?)?.toDouble(),
      height: (json['height'] as num?)?.toDouble(),
      x1: (json['x1'] as num?)?.toDouble(),
      y1: (json['y1'] as num?)?.toDouble(),
      x2: (json['x2'] as num?)?.toDouble(),
      y2: (json['y2'] as num?)?.toDouble(),
      thickness: (json['thickness'] as num?)?.toDouble(),
      color: json['color'] as String?,
      fontSize: (json['font_size'] as num?)?.toDouble(),
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'type': type,
        if (label != null) 'label': label,
        if (x != null) 'x': x,
        if (y != null) 'y': y,
        if (width != null) 'width': width,
        if (height != null) 'height': height,
        if (x1 != null) 'x1': x1,
        if (y1 != null) 'y1': y1,
        if (x2 != null) 'x2': x2,
        if (y2 != null) 'y2': y2,
        if (thickness != null) 'thickness': thickness,
        if (color != null) 'color': color,
        if (fontSize != null) 'font_size': fontSize,
      };
}

class FloorPlanData {
  final double canvasWidth;
  final double canvasHeight;
  final List<FloorPlanElement> elements;

  const FloorPlanData({
    this.canvasWidth = 960,
    this.canvasHeight = 640,
    this.elements = const [],
  });

  FloorPlanData copyWith({
    double? canvasWidth,
    double? canvasHeight,
    List<FloorPlanElement>? elements,
  }) {
    return FloorPlanData(
      canvasWidth: canvasWidth ?? this.canvasWidth,
      canvasHeight: canvasHeight ?? this.canvasHeight,
      elements: elements ?? this.elements,
    );
  }

  factory FloorPlanData.fromJson(Map<String, dynamic> json) {
    final rawElements = json['elements'] as List<dynamic>? ?? [];
    return FloorPlanData(
      canvasWidth: (json['canvas_width'] as num?)?.toDouble() ?? 960,
      canvasHeight: (json['canvas_height'] as num?)?.toDouble() ?? 640,
      elements: rawElements
          .map((e) => FloorPlanElement.fromJson(e as Map<String, dynamic>))
          .toList(),
    );
  }

  Map<String, dynamic> toJson() => {
        'canvas_width': canvasWidth,
        'canvas_height': canvasHeight,
        'elements': elements.map((e) => e.toJson()).toList(),
      };
}

class BookingModel {
  final String id;
  final String tenantId;
  final String outletId;
  final String? tableId;
  final String? tableName;
  final String? customerName;
  final String? customerContact;
  final int partySize;
  final String startsAt;
  final String? endsAt;
  final String status; // 'pending', 'confirmed', 'seated', 'completed', 'cancelled', 'no_show'
  final String? notes;

  const BookingModel({
    required this.id,
    required this.tenantId,
    required this.outletId,
    this.tableId,
    this.tableName,
    this.customerName,
    this.customerContact,
    required this.partySize,
    required this.startsAt,
    this.endsAt,
    this.status = 'pending',
    this.notes,
  });

  factory BookingModel.fromJson(Map<String, dynamic> json) {
    return BookingModel(
      id: json['id'] as String? ?? '',
      tenantId: json['tenant_id'] as String? ?? '',
      outletId: json['outlet_id'] as String? ?? '',
      tableId: json['table_id'] as String?,
      tableName: json['table'] is Map<String, dynamic>
          ? (json['table']['name'] as String?)
          : null,
      customerName: json['customer_name'] as String?,
      customerContact: json['customer_contact'] as String?,
      partySize: json['party_size'] as int? ?? 1,
      startsAt: json['starts_at'] as String? ?? '',
      endsAt: json['ends_at'] as String?,
      status: json['status'] as String? ?? 'pending',
      notes: json['notes'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'tenant_id': tenantId,
        'outlet_id': outletId,
        'table_id': tableId,
        'customer_name': customerName,
        'customer_contact': customerContact,
        'party_size': partySize,
        'starts_at': startsAt,
        'ends_at': endsAt,
        'status': status,
        'notes': notes,
      };
}
