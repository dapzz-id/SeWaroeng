class SubscriptionPlan {
  final String id;
  final String code;
  final String name;
  final String? description;
  final int priceMinor;
  final String currency;
  final String billingInterval;

  const SubscriptionPlan({
    required this.id,
    required this.code,
    required this.name,
    this.description,
    required this.priceMinor,
    required this.currency,
    required this.billingInterval,
  });

  factory SubscriptionPlan.fromJson(Map<String, dynamic> json) {
    return SubscriptionPlan(
      id: json['id'] as String? ?? '',
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      description: json['description'] as String?,
      priceMinor: (json['price_minor'] as num?)?.toInt() ?? 0,
      currency: json['currency'] as String? ?? 'IDR',
      billingInterval: json['billing_interval'] as String? ?? 'monthly',
    );
  }
}

class SubscriptionAddOnAssignment {
  final String id;
  final String code;
  final String name;
  final int quantity;
  final String status;

  const SubscriptionAddOnAssignment({
    required this.id,
    required this.code,
    required this.name,
    required this.quantity,
    required this.status,
  });

  factory SubscriptionAddOnAssignment.fromJson(Map<String, dynamic> json) {
    return SubscriptionAddOnAssignment(
      id: json['id'] as String? ?? '',
      code: json['code'] as String? ?? '',
      name: json['name'] as String? ?? '',
      quantity: (json['quantity'] as num?)?.toInt() ?? 1,
      status: json['status'] as String? ?? 'active',
    );
  }
}

class TenantSubscription {
  final String id;
  final String tenantId;
  final String status;
  final bool isOperational;
  final bool isTrial;
  final int trialRemainingDays;
  final DateTime? trialEndsAt;
  final DateTime startsAt;
  final DateTime currentPeriodStart;
  final DateTime currentPeriodEnd;
  final DateTime? cancelledAt;
  final SubscriptionPlan plan;
  final List<SubscriptionAddOnAssignment> addOns;

  const TenantSubscription({
    required this.id,
    required this.tenantId,
    required this.status,
    required this.isOperational,
    required this.isTrial,
    required this.trialRemainingDays,
    this.trialEndsAt,
    required this.startsAt,
    required this.currentPeriodStart,
    required this.currentPeriodEnd,
    this.cancelledAt,
    required this.plan,
    required this.addOns,
  });

  factory TenantSubscription.fromJson(Map<String, dynamic> json) {
    final planJson = json['plan'] as Map<String, dynamic>? ?? {};
    final addOnsList = (json['add_ons'] as List<dynamic>?) ?? [];

    return TenantSubscription(
      id: json['id'] as String? ?? '',
      tenantId: json['tenant_id'] as String? ?? '',
      status: json['status'] as String? ?? 'trialing',
      isOperational: json['is_operational'] as bool? ?? true,
      isTrial: json['is_trial'] as bool? ?? false,
      trialRemainingDays: (json['trial_remaining_days'] as num?)?.toInt() ?? 0,
      trialEndsAt: json['trial_ends_at'] != null ? DateTime.parse(json['trial_ends_at'] as String) : null,
      startsAt: json['starts_at'] != null ? DateTime.parse(json['starts_at'] as String) : DateTime.now(),
      currentPeriodStart: json['current_period_start'] != null ? DateTime.parse(json['current_period_start'] as String) : DateTime.now(),
      currentPeriodEnd: json['current_period_end'] != null ? DateTime.parse(json['current_period_end'] as String) : DateTime.now(),
      cancelledAt: json['cancelled_at'] != null ? DateTime.parse(json['cancelled_at'] as String) : null,
      plan: SubscriptionPlan.fromJson(planJson),
      addOns: addOnsList.map((a) => SubscriptionAddOnAssignment.fromJson(a as Map<String, dynamic>)).toList(),
    );
  }
}

class QuotaDimensionUsage {
  final int? limit;
  final int current;
  final int? remaining;
  final bool isUnlimited;

  const QuotaDimensionUsage({
    this.limit,
    required this.current,
    this.remaining,
    required this.isUnlimited,
  });

  factory QuotaDimensionUsage.fromJson(Map<String, dynamic> json) {
    return QuotaDimensionUsage(
      limit: (json['limit'] as num?)?.toInt(),
      current: (json['current'] as num?)?.toInt() ?? 0,
      remaining: (json['remaining'] as num?)?.toInt(),
      isUnlimited: json['is_unlimited'] as bool? ?? false,
    );
  }
}

class SubscriptionUsage {
  final QuotaDimensionUsage maxOutlets;
  final QuotaDimensionUsage maxUsers;
  final QuotaDimensionUsage maxProducts;
  final QuotaDimensionUsage maxMonthlyTransactions;

  const SubscriptionUsage({
    required this.maxOutlets,
    required this.maxUsers,
    required this.maxProducts,
    required this.maxMonthlyTransactions,
  });

  factory SubscriptionUsage.fromJson(Map<String, dynamic> json) {
    return SubscriptionUsage(
      maxOutlets: QuotaDimensionUsage.fromJson((json['max_outlets'] as Map<String, dynamic>?) ?? {}),
      maxUsers: QuotaDimensionUsage.fromJson((json['max_users'] as Map<String, dynamic>?) ?? {}),
      maxProducts: QuotaDimensionUsage.fromJson((json['max_products'] as Map<String, dynamic>?) ?? {}),
      maxMonthlyTransactions: QuotaDimensionUsage.fromJson((json['max_monthly_transactions'] as Map<String, dynamic>?) ?? {}),
    );
  }
}

class SubscriptionEntitlements {
  final Map<String, bool> features;

  const SubscriptionEntitlements({required this.features});

  bool can(String featureKey) => features[featureKey] ?? false;

  factory SubscriptionEntitlements.fromJson(Map<String, dynamic> json) {
    final featuresMap = (json['features'] as Map<String, dynamic>?) ?? {};
    return SubscriptionEntitlements(
      features: featuresMap.map((k, v) => MapEntry(k, v as bool? ?? false)),
    );
  }
}
