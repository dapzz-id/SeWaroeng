String formatIdrCurrency(int minor) {
  final isNegative = minor < 0;
  final absVal = minor.abs();
  final str = absVal.toString();
  final buffer = StringBuffer();
  for (int i = 0; i < str.length; i++) {
    if (i > 0 && (str.length - i) % 3 == 0) buffer.write('.');
    buffer.write(str[i]);
  }
  final formatted = buffer.toString();
  return isNegative ? '-Rp $formatted' : 'Rp $formatted';
}

class ExpenseModel {
  final String id;
  final String tenantId;
  final String? outletId;
  final String? outletName;
  final String? accountId;
  final String? accountCode;
  final String? accountName;
  final String category;
  final int amountMinor;
  final String expenseDate;
  final String paymentMethod;
  final String status; // draft, posted, reversed
  final String? description;
  final String? referenceNumber;
  final String? recordedBy;
  final DateTime? postedAt;
  final DateTime? reversedAt;
  final DateTime? createdAt;

  ExpenseModel({
    required this.id,
    required this.tenantId,
    this.outletId,
    this.outletName,
    this.accountId,
    this.accountCode,
    this.accountName,
    required this.category,
    required this.amountMinor,
    required this.expenseDate,
    required this.paymentMethod,
    required this.status,
    this.description,
    this.referenceNumber,
    this.recordedBy,
    this.postedAt,
    this.reversedAt,
    this.createdAt,
  });

  factory ExpenseModel.fromJson(Map<String, dynamic> json) {
    return ExpenseModel(
      id: json['id'] as String,
      tenantId: json['tenant_id'] as String? ?? '',
      outletId: json['outlet_id'] as String?,
      outletName: json['outlet'] != null ? json['outlet']['name'] as String? : null,
      accountId: json['account_id'] as String?,
      accountCode: json['account'] != null ? json['account']['code'] as String? : null,
      accountName: json['account'] != null ? json['account']['name'] as String? : null,
      category: json['category'] as String? ?? 'other',
      amountMinor: (json['amount_minor'] as num?)?.toInt() ?? 0,
      expenseDate: json['expense_date'] as String? ?? '',
      paymentMethod: json['payment_method'] as String? ?? 'cash',
      status: json['status'] as String? ?? 'posted',
      description: json['description'] as String?,
      referenceNumber: json['reference_number'] as String?,
      recordedBy: json['recorded_by'] as String?,
      postedAt: json['posted_at'] != null ? DateTime.tryParse(json['posted_at'] as String) : null,
      reversedAt: json['reversed_at'] != null ? DateTime.tryParse(json['reversed_at'] as String) : null,
      createdAt: json['created_at'] != null ? DateTime.tryParse(json['created_at'] as String) : null,
    );
  }

  bool get isPosted => status == 'posted';
  bool get isReversed => status == 'reversed';
  bool get isDraft => status == 'draft';

  String get formattedAmount => formatIdrCurrency(amountMinor);

  String get categoryLabel {
    switch (category) {
      case 'utilities':
        return 'Utilitas & Listrik';
      case 'supplies':
        return 'Bahan & Perlengkapan';
      case 'rent':
        return 'Sewa Tempat';
      case 'salary':
        return 'Gaji & Upah';
      case 'maintenance':
        return 'Pemeliharaan';
      case 'marketing':
        return 'Pemasaran';
      default:
        return 'Lain-lain';
    }
  }

  String get statusLabel {
    switch (status) {
      case 'posted':
        return 'Terposting';
      case 'reversed':
        return 'Dibatalkan';
      case 'draft':
        return 'Draf';
      default:
        return status;
    }
  }
}

class ProfitLossComparisonModel {
  final int previousRevenueMinor;
  final int previousCogsMinor;
  final int previousGrossProfitMinor;
  final int previousExpenseMinor;
  final int previousNetProfitMinor;
  final double revenueGrowthPct;
  final double netProfitGrowthPct;
  final String previousStartDate;
  final String previousEndDate;

  ProfitLossComparisonModel({
    required this.previousRevenueMinor,
    required this.previousCogsMinor,
    required this.previousGrossProfitMinor,
    required this.previousExpenseMinor,
    required this.previousNetProfitMinor,
    required this.revenueGrowthPct,
    required this.netProfitGrowthPct,
    required this.previousStartDate,
    required this.previousEndDate,
  });

  factory ProfitLossComparisonModel.fromJson(Map<String, dynamic> json) {
    final prevPeriod = json['previous_period'] as Map<String, dynamic>? ?? {};
    return ProfitLossComparisonModel(
      previousRevenueMinor: (json['previous_revenue_minor'] as num?)?.toInt() ?? 0,
      previousCogsMinor: (json['previous_cogs_minor'] as num?)?.toInt() ?? 0,
      previousGrossProfitMinor: (json['previous_gross_profit_minor'] as num?)?.toInt() ?? 0,
      previousExpenseMinor: (json['previous_expense_minor'] as num?)?.toInt() ?? 0,
      previousNetProfitMinor: (json['previous_net_profit_minor'] as num?)?.toInt() ?? 0,
      revenueGrowthPct: (json['revenue_growth_pct'] as num?)?.toDouble() ?? 0.0,
      netProfitGrowthPct: (json['net_profit_growth_pct'] as num?)?.toDouble() ?? 0.0,
      previousStartDate: prevPeriod['start_date'] as String? ?? '',
      previousEndDate: prevPeriod['end_date'] as String? ?? '',
    );
  }
}

class ProfitLossReportModel {
  final int revenueMinor;
  final int cogsMinor;
  final int grossProfitMinor;
  final double grossMarginPct;
  final int expenseMinor;
  final int netProfitMinor;
  final double netMarginPct;
  final int grossRevenueMinor;
  final int salesReturnMinor;
  final Map<String, int> categories;
  final String startDate;
  final String endDate;
  final ProfitLossComparisonModel? comparison;

  ProfitLossReportModel({
    required this.revenueMinor,
    required this.cogsMinor,
    required this.grossProfitMinor,
    required this.grossMarginPct,
    required this.expenseMinor,
    required this.netProfitMinor,
    required this.netMarginPct,
    required this.grossRevenueMinor,
    required this.salesReturnMinor,
    required this.categories,
    required this.startDate,
    required this.endDate,
    this.comparison,
  });

  factory ProfitLossReportModel.fromJson(Map<String, dynamic> json) {
    final breakdown = json['breakdown'] as Map<String, dynamic>? ?? {};
    final catMap = <String, int>{};
    if (breakdown['categories'] is Map) {
      (breakdown['categories'] as Map).forEach((k, v) {
        catMap[k.toString()] = (v as num?)?.toInt() ?? 0;
      });
    }

    final period = json['period'] as Map<String, dynamic>? ?? {};
    final compData = json['comparison'] as Map<String, dynamic>?;

    return ProfitLossReportModel(
      revenueMinor: (json['revenue_minor'] as num?)?.toInt() ?? 0,
      cogsMinor: (json['cogs_minor'] as num?)?.toInt() ?? 0,
      grossProfitMinor: (json['gross_profit_minor'] as num?)?.toInt() ?? 0,
      grossMarginPct: (json['gross_margin_pct'] as num?)?.toDouble() ?? 0.0,
      expenseMinor: (json['expense_minor'] as num?)?.toInt() ?? 0,
      netProfitMinor: (json['net_profit_minor'] as num?)?.toInt() ?? 0,
      netMarginPct: (json['net_margin_pct'] as num?)?.toDouble() ?? 0.0,
      grossRevenueMinor: (breakdown['gross_revenue_minor'] as num?)?.toInt() ?? 0,
      salesReturnMinor: (breakdown['sales_return_minor'] as num?)?.toInt() ?? 0,
      categories: catMap,
      startDate: period['start_date'] as String? ?? '',
      endDate: period['end_date'] as String? ?? '',
      comparison: compData != null ? ProfitLossComparisonModel.fromJson(compData) : null,
    );
  }

  static String formatCurrency(int amount) => formatIdrCurrency(amount);
}

class CashFlowReportModel {
  final int openingCashMinor;
  final int cashInMinor;
  final int cashOutMinor;
  final int netCashflowMinor;
  final int closingCashMinor;
  final int cashSalesMinor;
  final int cashExpensesMinor;
  final int cashRefundsMinor;
  final int otherCashInMinor;
  final int otherCashOutMinor;
  final String startDate;
  final String endDate;

  CashFlowReportModel({
    required this.openingCashMinor,
    required this.cashInMinor,
    required this.cashOutMinor,
    required this.netCashflowMinor,
    required this.closingCashMinor,
    required this.cashSalesMinor,
    required this.cashExpensesMinor,
    required this.cashRefundsMinor,
    required this.otherCashInMinor,
    required this.otherCashOutMinor,
    required this.startDate,
    required this.endDate,
  });

  factory CashFlowReportModel.fromJson(Map<String, dynamic> json) {
    final breakdown = json['breakdown'] as Map<String, dynamic>? ?? {};
    final period = json['period'] as Map<String, dynamic>? ?? {};

    return CashFlowReportModel(
      openingCashMinor: (json['opening_cash_minor'] as num?)?.toInt() ?? 0,
      cashInMinor: (json['cash_in_minor'] as num?)?.toInt() ?? 0,
      cashOutMinor: (json['cash_out_minor'] as num?)?.toInt() ?? 0,
      netCashflowMinor: (json['net_cashflow_minor'] as num?)?.toInt() ?? 0,
      closingCashMinor: (json['closing_cash_minor'] as num?)?.toInt() ?? 0,
      cashSalesMinor: (breakdown['cash_sales_minor'] as num?)?.toInt() ?? 0,
      cashExpensesMinor: (breakdown['cash_expenses_minor'] as num?)?.toInt() ?? 0,
      cashRefundsMinor: (breakdown['cash_refunds_minor'] as num?)?.toInt() ?? 0,
      otherCashInMinor: (breakdown['other_cash_in_minor'] as num?)?.toInt() ?? 0,
      otherCashOutMinor: (breakdown['other_cash_out_minor'] as num?)?.toInt() ?? 0,
      startDate: period['start_date'] as String? ?? '',
      endDate: period['end_date'] as String? ?? '',
    );
  }

  static String formatCurrency(int amount) => formatIdrCurrency(amount);
}

class BalanceSheetReportModel {
  final String asOfDate;
  final String timezone;
  final int cashMinor;
  final int clearingMinor;
  final int inventoryMinor;
  final int totalAssetsMinor;
  final int taxPayableMinor;
  final int totalLiabilitiesMinor;
  final int ownerEquityMinor;
  final int retainedEarningsMinor;
  final int totalEquityMinor;
  final int totalLiabilitiesAndEquityMinor;
  final bool isBalanced;
  final int discrepancyMinor;

  BalanceSheetReportModel({
    required this.asOfDate,
    required this.timezone,
    required this.cashMinor,
    required this.clearingMinor,
    required this.inventoryMinor,
    required this.totalAssetsMinor,
    required this.taxPayableMinor,
    required this.totalLiabilitiesMinor,
    required this.ownerEquityMinor,
    required this.retainedEarningsMinor,
    required this.totalEquityMinor,
    required this.totalLiabilitiesAndEquityMinor,
    required this.isBalanced,
    required this.discrepancyMinor,
  });

  factory BalanceSheetReportModel.fromJson(Map<String, dynamic> json) {
    final assets = json['assets'] as Map<String, dynamic>? ?? {};
    final liabilities = json['liabilities'] as Map<String, dynamic>? ?? {};
    final equity = json['equity'] as Map<String, dynamic>? ?? {};

    return BalanceSheetReportModel(
      asOfDate: json['as_of_date'] as String? ?? '',
      timezone: json['timezone'] as String? ?? 'Asia/Jakarta',
      cashMinor: (assets['cash_minor'] as num?)?.toInt() ?? 0,
      clearingMinor: (assets['clearing_minor'] as num?)?.toInt() ?? 0,
      inventoryMinor: (assets['inventory_minor'] as num?)?.toInt() ?? 0,
      totalAssetsMinor: (assets['total_assets_minor'] as num?)?.toInt() ?? 0,
      taxPayableMinor: (liabilities['tax_payable_minor'] as num?)?.toInt() ?? 0,
      totalLiabilitiesMinor: (liabilities['total_liabilities_minor'] as num?)?.toInt() ?? 0,
      ownerEquityMinor: (equity['owner_equity_minor'] as num?)?.toInt() ?? 0,
      retainedEarningsMinor: (equity['retained_earnings_minor'] as num?)?.toInt() ?? 0,
      totalEquityMinor: (equity['total_equity_minor'] as num?)?.toInt() ?? 0,
      totalLiabilitiesAndEquityMinor: (json['total_liabilities_and_equity_minor'] as num?)?.toInt() ?? 0,
      isBalanced: json['is_balanced'] as bool? ?? false,
      discrepancyMinor: (json['discrepancy_minor'] as num?)?.toInt() ?? 0,
    );
  }
}

class TrialBalanceAccountItem {
  final String accountId;
  final String accountCode;
  final String accountName;
  final String accountType;
  final int totalDebitMinor;
  final int totalCreditMinor;
  final int endingDebitMinor;
  final int endingCreditMinor;

  TrialBalanceAccountItem({
    required this.accountId,
    required this.accountCode,
    required this.accountName,
    required this.accountType,
    required this.totalDebitMinor,
    required this.totalCreditMinor,
    required this.endingDebitMinor,
    required this.endingCreditMinor,
  });

  factory TrialBalanceAccountItem.fromJson(Map<String, dynamic> json) {
    return TrialBalanceAccountItem(
      accountId: json['account_id'] as String? ?? '',
      accountCode: json['account_code'] as String? ?? '',
      accountName: json['account_name'] as String? ?? '',
      accountType: json['account_type'] as String? ?? '',
      totalDebitMinor: (json['total_debit_minor'] as num?)?.toInt() ?? 0,
      totalCreditMinor: (json['total_credit_minor'] as num?)?.toInt() ?? 0,
      endingDebitMinor: (json['ending_debit_minor'] as num?)?.toInt() ?? 0,
      endingCreditMinor: (json['ending_credit_minor'] as num?)?.toInt() ?? 0,
    );
  }
}

class TrialBalanceReportModel {
  final String asOfDate;
  final String timezone;
  final List<TrialBalanceAccountItem> accounts;
  final int totalDebitMinor;
  final int totalCreditMinor;
  final bool isBalanced;
  final int discrepancyMinor;

  TrialBalanceReportModel({
    required this.asOfDate,
    required this.timezone,
    required this.accounts,
    required this.totalDebitMinor,
    required this.totalCreditMinor,
    required this.isBalanced,
    required this.discrepancyMinor,
  });

  factory TrialBalanceReportModel.fromJson(Map<String, dynamic> json) {
    final rawAccounts = json['accounts'] as List<dynamic>? ?? [];
    return TrialBalanceReportModel(
      asOfDate: json['as_of_date'] as String? ?? '',
      timezone: json['timezone'] as String? ?? 'Asia/Jakarta',
      accounts: rawAccounts.map((a) => TrialBalanceAccountItem.fromJson(a as Map<String, dynamic>)).toList(),
      totalDebitMinor: (json['total_debit_minor'] as num?)?.toInt() ?? 0,
      totalCreditMinor: (json['total_credit_minor'] as num?)?.toInt() ?? 0,
      isBalanced: json['is_balanced'] as bool? ?? false,
      discrepancyMinor: (json['discrepancy_minor'] as num?)?.toInt() ?? 0,
    );
  }
}

class AccountLedgerLineItem {
  final String id;
  final String entryId;
  final String entryNumber;
  final String entryDate;
  final String sourceType;
  final String? sourceId;
  final String? description;
  final int debitMinor;
  final int creditMinor;
  final int runningBalanceMinor;

  AccountLedgerLineItem({
    required this.id,
    required this.entryId,
    required this.entryNumber,
    required this.entryDate,
    required this.sourceType,
    this.sourceId,
    this.description,
    required this.debitMinor,
    required this.creditMinor,
    required this.runningBalanceMinor,
  });

  factory AccountLedgerLineItem.fromJson(Map<String, dynamic> json) {
    return AccountLedgerLineItem(
      id: json['id'] as String? ?? '',
      entryId: json['entry_id'] as String? ?? '',
      entryNumber: json['entry_number'] as String? ?? '',
      entryDate: json['entry_date'] as String? ?? '',
      sourceType: json['source_type'] as String? ?? '',
      sourceId: json['source_id'] as String?,
      description: json['description'] as String?,
      debitMinor: (json['debit_minor'] as num?)?.toInt() ?? 0,
      creditMinor: (json['credit_minor'] as num?)?.toInt() ?? 0,
      runningBalanceMinor: (json['running_balance_minor'] as num?)?.toInt() ?? 0,
    );
  }
}

class AccountLedgerReportModel {
  final String accountCode;
  final String accountName;
  final String accountType;
  final String normalBalance;
  final String startDate;
  final String endDate;
  final int openingBalanceMinor;
  final int totalDebitMinor;
  final int totalCreditMinor;
  final int closingBalanceMinor;
  final List<AccountLedgerLineItem> lines;
  final int currentPage;
  final int lastPage;
  final int total;

  AccountLedgerReportModel({
    required this.accountCode,
    required this.accountName,
    required this.accountType,
    required this.normalBalance,
    required this.startDate,
    required this.endDate,
    required this.openingBalanceMinor,
    required this.totalDebitMinor,
    required this.totalCreditMinor,
    required this.closingBalanceMinor,
    required this.lines,
    required this.currentPage,
    required this.lastPage,
    required this.total,
  });

  factory AccountLedgerReportModel.fromJson(Map<String, dynamic> json) {
    final account = json['account'] as Map<String, dynamic>? ?? {};
    final period = json['period'] as Map<String, dynamic>? ?? {};
    final pagination = json['pagination'] as Map<String, dynamic>? ?? {};
    final rawLines = json['lines'] as List<dynamic>? ?? [];

    return AccountLedgerReportModel(
      accountCode: account['code'] as String? ?? '',
      accountName: account['name'] as String? ?? '',
      accountType: account['type'] as String? ?? '',
      normalBalance: account['normal_balance'] as String? ?? 'debit',
      startDate: period['start_date'] as String? ?? '',
      endDate: period['end_date'] as String? ?? '',
      openingBalanceMinor: (json['opening_balance_minor'] as num?)?.toInt() ?? 0,
      totalDebitMinor: (json['total_debit_minor'] as num?)?.toInt() ?? 0,
      totalCreditMinor: (json['total_credit_minor'] as num?)?.toInt() ?? 0,
      closingBalanceMinor: (json['closing_balance_minor'] as num?)?.toInt() ?? 0,
      lines: rawLines.map((l) => AccountLedgerLineItem.fromJson(l as Map<String, dynamic>)).toList(),
      currentPage: (pagination['current_page'] as num?)?.toInt() ?? 1,
      lastPage: (pagination['last_page'] as num?)?.toInt() ?? 1,
      total: (pagination['total'] as num?)?.toInt() ?? 0,
    );
  }
}

class ClearingReportModel {
  final String startDate;
  final String endDate;
  final String accountCode;
  final int clearingDebitMinor;
  final int clearingCreditMinor;
  final int netClearingMovementMinor;
  final int matchedCount;
  final int matchedAmountMinor;
  final int mismatchedCount;
  final int mismatchedAmountMinor;
  final int unresolvedCount;
  final int unresolvedAmountMinor;
  final int settledCount;
  final int settledAmountMinor;
  final int pendingSettlementCount;
  final int pendingSettlementAmountMinor;

  ClearingReportModel({
    required this.startDate,
    required this.endDate,
    required this.accountCode,
    required this.clearingDebitMinor,
    required this.clearingCreditMinor,
    required this.netClearingMovementMinor,
    required this.matchedCount,
    required this.matchedAmountMinor,
    required this.mismatchedCount,
    required this.mismatchedAmountMinor,
    required this.unresolvedCount,
    required this.unresolvedAmountMinor,
    required this.settledCount,
    required this.settledAmountMinor,
    required this.pendingSettlementCount,
    required this.pendingSettlementAmountMinor,
  });

  factory ClearingReportModel.fromJson(Map<String, dynamic> json) {
    final period = json['period'] as Map<String, dynamic>? ?? {};
    final ledger = json['clearing_ledger'] as Map<String, dynamic>? ?? {};
    final recon = json['reconciliation_verification'] as Map<String, dynamic>? ?? {};

    return ClearingReportModel(
      startDate: period['start_date'] as String? ?? '',
      endDate: period['end_date'] as String? ?? '',
      accountCode: ledger['account_code'] as String? ?? '1120',
      clearingDebitMinor: (ledger['debit_minor'] as num?)?.toInt() ?? 0,
      clearingCreditMinor: (ledger['credit_minor'] as num?)?.toInt() ?? 0,
      netClearingMovementMinor: (ledger['net_movement_minor'] as num?)?.toInt() ?? 0,
      matchedCount: (recon['matched_count'] as num?)?.toInt() ?? 0,
      matchedAmountMinor: (recon['matched_amount_minor'] as num?)?.toInt() ?? 0,
      mismatchedCount: (recon['mismatched_count'] as num?)?.toInt() ?? 0,
      mismatchedAmountMinor: (recon['mismatched_amount_minor'] as num?)?.toInt() ?? 0,
      unresolvedCount: (recon['unresolved_count'] as num?)?.toInt() ?? 0,
      unresolvedAmountMinor: (recon['unresolved_amount_minor'] as num?)?.toInt() ?? 0,
      settledCount: (recon['settled_count'] as num?)?.toInt() ?? 0,
      settledAmountMinor: (recon['settled_amount_minor'] as num?)?.toInt() ?? 0,
      pendingSettlementCount: (recon['pending_settlement_count'] as num?)?.toInt() ?? 0,
      pendingSettlementAmountMinor: (recon['pending_settlement_amount_minor'] as num?)?.toInt() ?? 0,
    );
  }
}

class JournalLineModel {
  final String id;
  final String? accountCode;
  final String? accountName;
  final int debitMinor;
  final int creditMinor;
  final String? description;

  JournalLineModel({
    required this.id,
    this.accountCode,
    this.accountName,
    required this.debitMinor,
    required this.creditMinor,
    this.description,
  });

  factory JournalLineModel.fromJson(Map<String, dynamic> json) {
    return JournalLineModel(
      id: json['id'] as String,
      accountCode: json['account_code'] as String?,
      accountName: json['account_name'] as String?,
      debitMinor: (json['debit_minor'] as num?)?.toInt() ?? 0,
      creditMinor: (json['credit_minor'] as num?)?.toInt() ?? 0,
      description: json['description'] as String?,
    );
  }
}

class JournalEntryModel {
  final String id;
  final String entryNumber;
  final String entryDate;
  final String status;
  final String sourceType;
  final String? sourceId;
  final String? description;
  final int totalDebitMinor;
  final int totalCreditMinor;
  final bool isBalanced;
  final List<JournalLineModel> lines;

  JournalEntryModel({
    required this.id,
    required this.entryNumber,
    required this.entryDate,
    required this.status,
    required this.sourceType,
    this.sourceId,
    this.description,
    required this.totalDebitMinor,
    required this.totalCreditMinor,
    required this.isBalanced,
    required this.lines,
  });

  factory JournalEntryModel.fromJson(Map<String, dynamic> json) {
    final rawLines = json['lines'] as List<dynamic>? ?? [];
    return JournalEntryModel(
      id: json['id'] as String,
      entryNumber: json['entry_number'] as String? ?? '',
      entryDate: json['entry_date'] as String? ?? '',
      status: json['status'] as String? ?? 'posted',
      sourceType: json['source_type'] as String? ?? 'manual',
      sourceId: json['source_id'] as String?,
      description: json['description'] as String?,
      totalDebitMinor: (json['total_debit_minor'] as num?)?.toInt() ?? 0,
      totalCreditMinor: (json['total_credit_minor'] as num?)?.toInt() ?? 0,
      isBalanced: json['is_balanced'] as bool? ?? false,
      lines: rawLines.map((l) => JournalLineModel.fromJson(l as Map<String, dynamic>)).toList(),
    );
  }
}
