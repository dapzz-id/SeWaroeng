class PaymentReconciliationModel {
  final String id;
  final String tenantId;
  final String? outletId;
  final String? paymentId;
  final String provider;
  final String providerReference;
  final String? channel;
  final int? expectedAmountMinor;
  final int providerAmountMinor;
  final int feeAmountMinor;
  final int? netAmountMinor;
  final String currency;
  final String? paymentStatus;
  final String settlementStatus;
  final String reconciliationStatus;
  final String? mismatchReason;
  final DateTime? reconciledAt;
  final DateTime? resolvedAt;
  final String? resolutionNotes;
  final Map<String, dynamic>? metadata;

  const PaymentReconciliationModel({
    required this.id,
    required this.tenantId,
    this.outletId,
    this.paymentId,
    required this.provider,
    required this.providerReference,
    this.channel,
    this.expectedAmountMinor,
    required this.providerAmountMinor,
    this.feeAmountMinor = 0,
    this.netAmountMinor,
    this.currency = 'IDR',
    this.paymentStatus,
    this.settlementStatus = 'pending',
    this.reconciliationStatus = 'unmatched',
    this.mismatchReason,
    this.reconciledAt,
    this.resolvedAt,
    this.resolutionNotes,
    this.metadata,
  });

  factory PaymentReconciliationModel.fromJson(Map<String, dynamic> json) {
    return PaymentReconciliationModel(
      id: json['id'] as String? ?? '',
      tenantId: json['tenant_id'] as String? ?? '',
      outletId: json['outlet_id'] as String?,
      paymentId: json['payment_id'] as String?,
      provider: json['provider'] as String? ?? 'unknown',
      providerReference: json['provider_reference'] as String? ?? '',
      channel: json['channel'] as String?,
      expectedAmountMinor: json['expected_amount_minor'] != null
          ? (json['expected_amount_minor'] as num).toInt()
          : null,
      providerAmountMinor:
          (json['provider_amount_minor'] as num?)?.toInt() ?? 0,
      feeAmountMinor: (json['fee_amount_minor'] as num?)?.toInt() ?? 0,
      netAmountMinor: json['net_amount_minor'] != null
          ? (json['net_amount_minor'] as num).toInt()
          : null,
      currency: json['currency'] as String? ?? 'IDR',
      paymentStatus: json['payment_status'] as String?,
      settlementStatus: json['settlement_status'] as String? ?? 'pending',
      reconciliationStatus:
          (json['reconciliation_status'] ?? json['status']) as String? ??
              'unmatched',
      mismatchReason: json['mismatch_reason'] as String?,
      reconciledAt: json['reconciled_at'] != null
          ? DateTime.tryParse(json['reconciled_at'].toString())
          : null,
      resolvedAt: json['resolved_at'] != null
          ? DateTime.tryParse(json['resolved_at'].toString())
          : null,
      resolutionNotes: json['resolution_notes'] as String?,
      metadata: json['metadata'] as Map<String, dynamic>?,
    );
  }

  bool get isMatched => reconciliationStatus == 'matched';
  bool get isMismatched => reconciliationStatus == 'mismatched';
  bool get isUnmatched => reconciliationStatus == 'unmatched';
  bool get isNeedsReview => reconciliationStatus == 'needs_review';
  bool get isResolved => reconciliationStatus == 'resolved';
  bool get hasDiscrepancy =>
      isMismatched ||
      (expectedAmountMinor != null &&
          providerAmountMinor != expectedAmountMinor);
  int get discrepancyMinor => expectedAmountMinor != null
      ? (expectedAmountMinor! - providerAmountMinor).abs()
      : 0;
}

class ReconciliationSummaryModel {
  final int totalRecords;
  final int matchedCount;
  final int unmatchedCount;
  final int mismatchedCount;
  final int needsReviewCount;
  final int resolvedCount;
  final int totalProviderAmountMinor;
  final int totalExpectedAmountMinor;
  final int totalFeeMinor;
  final int totalNetAmountMinor;

  const ReconciliationSummaryModel({
    required this.totalRecords,
    required this.matchedCount,
    required this.unmatchedCount,
    required this.mismatchedCount,
    required this.needsReviewCount,
    required this.resolvedCount,
    required this.totalProviderAmountMinor,
    required this.totalExpectedAmountMinor,
    required this.totalFeeMinor,
    required this.totalNetAmountMinor,
  });

  int get totalReconciled => totalRecords;
  int get totalDiscrepancyMinor =>
      (totalExpectedAmountMinor - totalProviderAmountMinor).abs();

  factory ReconciliationSummaryModel.fromJson(Map<String, dynamic> json) {
    return ReconciliationSummaryModel(
      totalRecords: ((json['total_records'] ?? json['total_reconciled'])
              as num?)
          ?.toInt() ??
          0,
      matchedCount: (json['matched_count'] as num?)?.toInt() ?? 0,
      unmatchedCount: (json['unmatched_count'] as num?)?.toInt() ?? 0,
      mismatchedCount: (json['mismatched_count'] as num?)?.toInt() ?? 0,
      needsReviewCount: (json['needs_review_count'] as num?)?.toInt() ?? 0,
      resolvedCount: (json['resolved_count'] as num?)?.toInt() ?? 0,
      totalProviderAmountMinor:
          ((json['total_provider_amount_minor'] ??
                      json['total_settled_minor'] ??
                      json['total_provider_minor']) as num?)
                  ?.toInt() ??
              0,
      totalExpectedAmountMinor:
          ((json['total_expected_amount_minor'] ??
                      json['total_expected_minor']) as num?)
                  ?.toInt() ??
              0,
      totalFeeMinor: (json['total_fee_minor'] as num?)?.toInt() ?? 0,
      totalNetAmountMinor:
          (json['total_net_amount_minor'] as num?)?.toInt() ?? 0,
    );
  }

  factory ReconciliationSummaryModel.empty() {
    return const ReconciliationSummaryModel(
      totalRecords: 0,
      matchedCount: 0,
      unmatchedCount: 0,
      mismatchedCount: 0,
      needsReviewCount: 0,
      resolvedCount: 0,
      totalProviderAmountMinor: 0,
      totalExpectedAmountMinor: 0,
      totalFeeMinor: 0,
      totalNetAmountMinor: 0,
    );
  }
}
