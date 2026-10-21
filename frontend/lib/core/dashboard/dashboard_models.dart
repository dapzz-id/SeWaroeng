class DashboardPeriod {
  final String name;
  final String startDate;
  final String endDate;
  final String timezone;

  const DashboardPeriod({
    required this.name,
    required this.startDate,
    required this.endDate,
    required this.timezone,
  });

  factory DashboardPeriod.fromJson(Map<String, dynamic> json) {
    return DashboardPeriod(
      name: json['name'] as String? ?? 'today',
      startDate: json['start_date'] as String? ?? '',
      endDate: json['end_date'] as String? ?? '',
      timezone: json['timezone'] as String? ?? 'Asia/Jakarta',
    );
  }
}

class DashboardKpis {
  final int grossSalesMinor;
  final int refundsMinor;
  final int netSalesMinor;
  final int transactionCount;
  final int averageOrderValueMinor;
  final int? grossProfitMinor;
  final int? cogsMinor;
  final int? netCashflowMinor;

  const DashboardKpis({
    required this.grossSalesMinor,
    required this.refundsMinor,
    required this.netSalesMinor,
    required this.transactionCount,
    required this.averageOrderValueMinor,
    this.grossProfitMinor,
    this.cogsMinor,
    this.netCashflowMinor,
  });

  factory DashboardKpis.fromJson(Map<String, dynamic> json) {
    return DashboardKpis(
      grossSalesMinor: (json['gross_sales_minor'] as num?)?.toInt() ?? 0,
      refundsMinor: (json['refunds_minor'] as num?)?.toInt() ?? 0,
      netSalesMinor: (json['net_sales_minor'] as num?)?.toInt() ?? 0,
      transactionCount: (json['transaction_count'] as num?)?.toInt() ?? 0,
      averageOrderValueMinor: (json['average_order_value_minor'] as num?)?.toInt() ?? 0,
      grossProfitMinor: (json['gross_profit_minor'] as num?)?.toInt(),
      cogsMinor: (json['cogs_minor'] as num?)?.toInt(),
      netCashflowMinor: (json['net_cashflow_minor'] as num?)?.toInt(),
    );
  }
}

class TopProductItem {
  final String productName;
  final double unitsSold;
  final int revenueMinor;

  const TopProductItem({
    required this.productName,
    required this.unitsSold,
    required this.revenueMinor,
  });

  factory TopProductItem.fromJson(Map<String, dynamic> json) {
    return TopProductItem(
      productName: json['product_name'] as String? ?? '',
      unitsSold: (json['units_sold'] as num?)?.toDouble() ?? 0.0,
      revenueMinor: (json['revenue_minor'] as num?)?.toInt() ?? 0,
    );
  }
}

class LowStockItem {
  final String itemId;
  final String itemName;
  final String sku;
  final double available;
  final double reorderLevel;
  final String locationName;
  final String outletId;

  const LowStockItem({
    required this.itemId,
    required this.itemName,
    required this.sku,
    required this.available,
    required this.reorderLevel,
    required this.locationName,
    required this.outletId,
  });

  factory LowStockItem.fromJson(Map<String, dynamic> json) {
    return LowStockItem(
      itemId: json['item_id'] as String? ?? '',
      itemName: json['item_name'] as String? ?? '',
      sku: json['sku'] as String? ?? '',
      available: (json['available'] as num?)?.toDouble() ?? 0.0,
      reorderLevel: (json['reorder_level'] as num?)?.toDouble() ?? 0.0,
      locationName: json['location_name'] as String? ?? '',
      outletId: json['outlet_id'] as String? ?? '',
    );
  }
}

class TopCustomerItem {
  final String customerId;
  final String customerName;
  final int transactionCount;
  final int totalSpendMinor;

  const TopCustomerItem({
    required this.customerId,
    required this.customerName,
    required this.transactionCount,
    required this.totalSpendMinor,
  });

  factory TopCustomerItem.fromJson(Map<String, dynamic> json) {
    return TopCustomerItem(
      customerId: json['customer_id'] as String? ?? '',
      customerName: json['customer_name'] as String? ?? '',
      transactionCount: (json['transaction_count'] as num?)?.toInt() ?? 0,
      totalSpendMinor: (json['total_spend_minor'] as num?)?.toInt() ?? 0,
    );
  }
}

class OutletSummaryItem {
  final String outletId;
  final String outletName;
  final int transactionCount;
  final int grossSalesMinor;
  final int aovMinor;

  const OutletSummaryItem({
    required this.outletId,
    required this.outletName,
    required this.transactionCount,
    required this.grossSalesMinor,
    required this.aovMinor,
  });

  factory OutletSummaryItem.fromJson(Map<String, dynamic> json) {
    return OutletSummaryItem(
      outletId: json['outlet_id'] as String? ?? '',
      outletName: json['outlet_name'] as String? ?? '',
      transactionCount: (json['transaction_count'] as num?)?.toInt() ?? 0,
      grossSalesMinor: (json['gross_sales_minor'] as num?)?.toInt() ?? 0,
      aovMinor: (json['aov_minor'] as num?)?.toInt() ?? 0,
    );
  }
}

class ShiftSummaryItem {
  final String shiftId;
  final String status;
  final int openingCash;
  final int expectedClosingCash;
  final int actualClosingCash;
  final int variance;

  const ShiftSummaryItem({
    required this.shiftId,
    required this.status,
    required this.openingCash,
    required this.expectedClosingCash,
    required this.actualClosingCash,
    required this.variance,
  });

  factory ShiftSummaryItem.fromJson(Map<String, dynamic> json) {
    return ShiftSummaryItem(
      shiftId: json['shift_id'] as String? ?? '',
      status: json['status'] as String? ?? '',
      openingCash: (json['opening_cash'] as num?)?.toInt() ?? 0,
      expectedClosingCash: (json['expected_closing_cash'] as num?)?.toInt() ?? 0,
      actualClosingCash: (json['actual_closing_cash'] as num?)?.toInt() ?? 0,
      variance: (json['variance'] as num?)?.toInt() ?? 0,
    );
  }
}

class AlertsSummary {
  final int unreadCount;
  final bool hasCritical;

  const AlertsSummary({
    required this.unreadCount,
    required this.hasCritical,
  });

  factory AlertsSummary.fromJson(Map<String, dynamic> json) {
    return AlertsSummary(
      unreadCount: (json['unread_count'] as num?)?.toInt() ?? 0,
      hasCritical: json['has_critical'] as bool? ?? false,
    );
  }
}

class DashboardData {
  final DashboardPeriod period;
  final DashboardKpis kpi;
  final List<TopProductItem> topProducts;
  final List<LowStockItem> lowStock;
  final List<TopCustomerItem> topCustomers;
  final Map<String, dynamic> paymentSummary;
  final List<OutletSummaryItem> outletSummary;
  final ShiftSummaryItem? shiftSummary;
  final AlertsSummary alerts;

  const DashboardData({
    required this.period,
    required this.kpi,
    required this.topProducts,
    required this.lowStock,
    required this.topCustomers,
    required this.paymentSummary,
    required this.outletSummary,
    this.shiftSummary,
    required this.alerts,
  });

  factory DashboardData.fromJson(Map<String, dynamic> json) {
    return DashboardData(
      period: DashboardPeriod.fromJson(
        json['period'] is Map ? (json['period'] as Map).cast<String, dynamic>() : {},
      ),
      kpi: DashboardKpis.fromJson(
        json['kpi'] is Map ? (json['kpi'] as Map).cast<String, dynamic>() : {},
      ),
      topProducts: ((json['top_products'] as List<dynamic>?) ?? [])
          .map((i) => TopProductItem.fromJson((i as Map).cast<String, dynamic>()))
          .toList(),
      lowStock: ((json['low_stock'] as List<dynamic>?) ?? [])
          .map((i) => LowStockItem.fromJson((i as Map).cast<String, dynamic>()))
          .toList(),
      topCustomers: ((json['top_customers'] as List<dynamic>?) ?? [])
          .map((i) => TopCustomerItem.fromJson((i as Map).cast<String, dynamic>()))
          .toList(),
      paymentSummary: json['payment_summary'] is Map
          ? (json['payment_summary'] as Map).cast<String, dynamic>()
          : {},
      outletSummary: ((json['outlet_summary'] as List<dynamic>?) ?? [])
          .map((i) => OutletSummaryItem.fromJson((i as Map).cast<String, dynamic>()))
          .toList(),
      shiftSummary: json['shift_summary'] is Map
          ? ShiftSummaryItem.fromJson((json['shift_summary'] as Map).cast<String, dynamic>())
          : null,
      alerts: AlertsSummary.fromJson(
        json['alerts'] is Map ? (json['alerts'] as Map).cast<String, dynamic>() : {},
      ),
    );
  }
}
