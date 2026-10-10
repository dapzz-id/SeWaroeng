import 'package:flutter/foundation.dart';

@immutable
class LoyaltyProgramModel {
  final String id;
  final String name;
  final String status;
  final int pointsPerCurrency;
  final int earnUnitAmountMinor;
  final int minimumEarnAmountMinor;
  final int redemptionValueMinor;
  final String roundingPolicy;
  final int? expirationDays;
  final bool isActive;
  final int tiersCount;
  final int rewardsCount;

  const LoyaltyProgramModel({
    required this.id,
    required this.name,
    this.status = 'active',
    this.pointsPerCurrency = 1,
    this.earnUnitAmountMinor = 100000,
    this.minimumEarnAmountMinor = 1000000,
    this.redemptionValueMinor = 10000,
    this.roundingPolicy = 'floor',
    this.expirationDays,
    this.isActive = true,
    this.tiersCount = 0,
    this.rewardsCount = 0,
  });

  factory LoyaltyProgramModel.fromJson(Map<String, dynamic> json) {
    return LoyaltyProgramModel(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? 'Program Loyalitas',
      status: json['status'] as String? ?? 'active',
      pointsPerCurrency: (json['points_per_currency'] as num?)?.toInt() ?? 1,
      earnUnitAmountMinor: (json['earn_unit_amount_minor'] as num?)?.toInt() ?? 100000,
      minimumEarnAmountMinor: (json['minimum_earn_amount_minor'] as num?)?.toInt() ?? 1000000,
      redemptionValueMinor: (json['redemption_value_minor'] as num?)?.toInt() ?? 10000,
      roundingPolicy: json['rounding_policy'] as String? ?? 'floor',
      expirationDays: (json['expiration_days'] as num?)?.toInt(),
      isActive: json['is_active'] as bool? ?? true,
      tiersCount: (json['tiers_count'] as num?)?.toInt() ?? 0,
      rewardsCount: (json['rewards_count'] as num?)?.toInt() ?? 0,
    );
  }
}

@immutable
class LoyaltyTierModel {
  final String id;
  final String name;
  final int rank;
  final int minPointsEarned;
  final int multiplierBps;
  final String multiplierFormatted;
  final Map<String, dynamic>? perks;
  final bool isActive;

  const LoyaltyTierModel({
    required this.id,
    required this.name,
    required this.rank,
    required this.minPointsEarned,
    this.multiplierBps = 10000,
    this.multiplierFormatted = '1.0x',
    this.perks,
    this.isActive = true,
  });

  factory LoyaltyTierModel.fromJson(Map<String, dynamic> json) {
    return LoyaltyTierModel(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? 'Bronze',
      rank: (json['rank'] as num?)?.toInt() ?? 1,
      minPointsEarned: (json['min_points_earned'] as num?)?.toInt() ?? 0,
      multiplierBps: (json['multiplier_bps'] as num?)?.toInt() ?? 10000,
      multiplierFormatted: json['multiplier_formatted'] as String? ?? '1.0x',
      perks: json['perks'] as Map<String, dynamic>?,
      isActive: json['is_active'] as bool? ?? true,
    );
  }
}

@immutable
class CustomerLoyaltyAccountModel {
  final String accountId;
  final String customerId;
  final int currentBalance;
  final int lifetimeEarned;
  final int lifetimeRedeemed;
  final String status;
  final String? joinedAt;
  final LoyaltyTierModel? currentTier;
  final Map<String, dynamic>? nextTier;
  final int activeVouchersCount;

  const CustomerLoyaltyAccountModel({
    required this.accountId,
    required this.customerId,
    this.currentBalance = 0,
    this.lifetimeEarned = 0,
    this.lifetimeRedeemed = 0,
    this.status = 'active',
    this.joinedAt,
    this.currentTier,
    this.nextTier,
    this.activeVouchersCount = 0,
  });

  factory CustomerLoyaltyAccountModel.fromJson(Map<String, dynamic> json) {
    return CustomerLoyaltyAccountModel(
      accountId: json['account_id'] as String? ?? '',
      customerId: json['customer_id'] as String? ?? '',
      currentBalance: (json['current_balance'] as num?)?.toInt() ?? 0,
      lifetimeEarned: (json['lifetime_earned'] as num?)?.toInt() ?? 0,
      lifetimeRedeemed: (json['lifetime_redeemed'] as num?)?.toInt() ?? 0,
      status: json['status'] as String? ?? 'active',
      joinedAt: json['joined_at'] as String?,
      currentTier: json['current_tier'] != null
          ? LoyaltyTierModel.fromJson(json['current_tier'] as Map<String, dynamic>)
          : null,
      nextTier: json['next_tier'] as Map<String, dynamic>?,
      activeVouchersCount: (json['active_vouchers_count'] as num?)?.toInt() ?? 0,
    );
  }
}

@immutable
class LoyaltyLedgerItemModel {
  final String id;
  final String type;
  final int points;
  final int balanceAfter;
  final String? referenceType;
  final String? referenceId;
  final String? notes;
  final String? createdAt;

  const LoyaltyLedgerItemModel({
    required this.id,
    required this.type,
    required this.points,
    required this.balanceAfter,
    this.referenceType,
    this.referenceId,
    this.notes,
    this.createdAt,
  });

  factory LoyaltyLedgerItemModel.fromJson(Map<String, dynamic> json) {
    return LoyaltyLedgerItemModel(
      id: json['id'] as String? ?? '',
      type: json['type'] as String? ?? 'purchase',
      points: (json['points'] as num?)?.toInt() ?? 0,
      balanceAfter: (json['balance_after'] as num?)?.toInt() ?? 0,
      referenceType: json['reference_type'] as String?,
      referenceId: json['reference_id'] as String?,
      notes: json['notes'] as String?,
      createdAt: json['created_at'] as String?,
    );
  }
}

@immutable
class LoyaltyRewardModel {
  final String id;
  final String name;
  final String? description;
  final int pointsCost;
  final String rewardType;
  final int discountAmountMinor;
  final int? discountPercent;
  final int minPurchaseMinor;
  final int? stock;
  final int validityDays;
  final bool isActive;

  const LoyaltyRewardModel({
    required this.id,
    required this.name,
    this.description,
    required this.pointsCost,
    this.rewardType = 'discount_fixed',
    this.discountAmountMinor = 0,
    this.discountPercent,
    this.minPurchaseMinor = 0,
    this.stock,
    this.validityDays = 30,
    this.isActive = true,
  });

  factory LoyaltyRewardModel.fromJson(Map<String, dynamic> json) {
    return LoyaltyRewardModel(
      id: json['id'] as String? ?? '',
      name: json['name'] as String? ?? '',
      description: json['description'] as String?,
      pointsCost: (json['points_cost'] as num?)?.toInt() ?? 0,
      rewardType: json['reward_type'] as String? ?? 'discount_fixed',
      discountAmountMinor: (json['discount_amount_minor'] as num?)?.toInt() ?? 0,
      discountPercent: (json['discount_percent'] as num?)?.toInt(),
      minPurchaseMinor: (json['min_purchase_minor'] as num?)?.toInt() ?? 0,
      stock: (json['stock'] as num?)?.toInt(),
      validityDays: (json['validity_days'] as num?)?.toInt() ?? 30,
      isActive: json['is_active'] as bool? ?? true,
    );
  }
}

@immutable
class LoyaltyVoucherModel {
  final String id;
  final String code;
  final String status;
  final String rewardName;
  final int discountAmountMinor;
  final int? discountPercent;
  final int minPurchaseMinor;
  final bool isValid;
  final String? issuedAt;
  final String? expiresAt;
  final String? usedAt;

  const LoyaltyVoucherModel({
    required this.id,
    required this.code,
    this.status = 'active',
    this.rewardName = 'Reward Diskon',
    this.discountAmountMinor = 0,
    this.discountPercent,
    this.minPurchaseMinor = 0,
    this.isValid = true,
    this.issuedAt,
    this.expiresAt,
    this.usedAt,
  });

  factory LoyaltyVoucherModel.fromJson(Map<String, dynamic> json) {
    return LoyaltyVoucherModel(
      id: json['id'] as String? ?? '',
      code: json['code'] as String? ?? '',
      status: json['status'] as String? ?? 'active',
      rewardName: json['reward_name'] as String? ?? 'Reward Diskon',
      discountAmountMinor: (json['discount_amount_minor'] as num?)?.toInt() ?? 0,
      discountPercent: (json['discount_percent'] as num?)?.toInt(),
      minPurchaseMinor: (json['min_purchase_minor'] as num?)?.toInt() ?? 0,
      isValid: json['is_valid'] as bool? ?? true,
      issuedAt: json['issued_at'] as String?,
      expiresAt: json['expires_at'] as String?,
      usedAt: json['used_at'] as String?,
    );
  }
}
