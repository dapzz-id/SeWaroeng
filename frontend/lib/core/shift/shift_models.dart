class ShiftModel {
  final String id;
  final String tenantId;
  final String outletId;
  final String openedByUserId;
  final String? closedByUserId;
  final String status;
  final DateTime openedAt;
  final DateTime? closedAt;
  final int openingCashMinor;
  final int? closingCashMinor;
  final int? expectedCashMinor;
  final int? varianceMinor;
  final String? notes;
  final String? openedByName;
  final String? closedByName;
  final List<CashMovementModel> movements;

  const ShiftModel({
    required this.id,
    required this.tenantId,
    required this.outletId,
    required this.openedByUserId,
    this.closedByUserId,
    required this.status,
    required this.openedAt,
    this.closedAt,
    required this.openingCashMinor,
    this.closingCashMinor,
    this.expectedCashMinor,
    this.varianceMinor,
    this.notes,
    this.openedByName,
    this.closedByName,
    this.movements = const [],
  });

  bool get isOpen => status == 'open';
  bool get isClosed => status == 'closed';

  factory ShiftModel.fromJson(Map<String, dynamic> json) {
    var rawMovements = json['movements'];
    List<CashMovementModel> parsedMovements = [];
    if (rawMovements is List) {
      parsedMovements = rawMovements
          .map((m) => CashMovementModel.fromJson(m as Map<String, dynamic>))
          .toList();
    }

    return ShiftModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String,
      outletId: json['outlet_id'] as String,
      openedByUserId: json['opened_by_user_id'] as String,
      closedByUserId: json['closed_by_user_id'] as String?,
      status: json['status'] as String? ?? 'open',
      openedAt: DateTime.parse(json['opened_at'] as String),
      closedAt: json['closed_at'] != null
          ? DateTime.parse(json['closed_at'] as String)
          : null,
      openingCashMinor: json['opening_cash_minor'] as int? ?? 0,
      closingCashMinor: json['closing_cash_minor'] as int?,
      expectedCashMinor: json['expected_cash_minor'] as int?,
      varianceMinor: json['variance_minor'] as int?,
      notes: json['notes'] as String?,
      openedByName: json['opened_by']?['name'] as String?,
      closedByName: json['closed_by']?['name'] as String?,
      movements: parsedMovements,
    );
  }
}

class CashMovementModel {
  final String id;
  final String tenantId;
  final String outletId;
  final String shiftId;
  final String type;
  final String direction;
  final int amountMinor;
  final String? referenceType;
  final String? referenceId;
  final String? reason;
  final String performedByUserId;
  final String? performedByName;
  final DateTime createdAt;

  const CashMovementModel({
    required this.id,
    required this.tenantId,
    required this.outletId,
    required this.shiftId,
    required this.type,
    required this.direction,
    required this.amountMinor,
    this.referenceType,
    this.referenceId,
    this.reason,
    required this.performedByUserId,
    this.performedByName,
    required this.createdAt,
  });

  bool get isIn => direction == 'in';
  bool get isOut => direction == 'out';

  factory CashMovementModel.fromJson(Map<String, dynamic> json) {
    return CashMovementModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String,
      outletId: json['outlet_id'] as String,
      shiftId: json['shift_id'] as String,
      type: json['type'] as String? ?? 'sale',
      direction: json['direction'] as String? ?? 'in',
      amountMinor: json['amount_minor'] as int? ?? 0,
      referenceType: json['reference_type'] as String?,
      referenceId: json['reference_id'] as String?,
      reason: json['reason'] as String?,
      performedByUserId: json['performed_by_user_id'] as String,
      performedByName: json['performed_by']?['name'] as String?,
      createdAt: DateTime.parse(json['created_at'] as String),
    );
  }
}

class CashSummaryModel {
  final int openingCash;
  final int cashSales;
  final int cashIn;
  final int cashOut;
  final int cashRefund;
  final int adjustments;
  final int expectedClosing;
  final int movementCount;

  const CashSummaryModel({
    required this.openingCash,
    required this.cashSales,
    required this.cashIn,
    required this.cashOut,
    required this.cashRefund,
    required this.adjustments,
    required this.expectedClosing,
    required this.movementCount,
  });

  factory CashSummaryModel.fromJson(Map<String, dynamic> json) {
    return CashSummaryModel(
      openingCash: json['opening_cash'] as int? ?? 0,
      cashSales: json['cash_sales'] as int? ?? 0,
      cashIn: json['cash_in'] as int? ?? 0,
      cashOut: json['cash_out'] as int? ?? 0,
      cashRefund: json['cash_refund'] as int? ?? 0,
      adjustments: json['adjustments'] as int? ?? 0,
      expectedClosing: json['expected_closing'] as int? ?? 0,
      movementCount: json['movement_count'] as int? ?? 0,
    );
  }
}
