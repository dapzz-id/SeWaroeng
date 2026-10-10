import '../api/api_client.dart';
import 'loyalty_models.dart';

class LoyaltyService {
  final ApiClient apiClient;

  LoyaltyService({required this.apiClient});

  /// Fetch active loyalty program configuration.
  Future<LoyaltyProgramModel?> getProgram() async {
    final response = await apiClient.get<Map<String, dynamic>>('/loyalty/program');
    if (response.isSuccess && response.data != null) {
      return LoyaltyProgramModel.fromJson(response.data!);
    }
    return null;
  }

  /// Update loyalty program configuration.
  Future<bool> updateProgram(Map<String, dynamic> data) async {
    final response = await apiClient.put<Map<String, dynamic>>(
      '/loyalty/program',
      body: data,
    );
    return response.isSuccess;
  }

  /// List tiers.
  Future<List<LoyaltyTierModel>> getTiers() async {
    final response = await apiClient.get<List<dynamic>>('/loyalty/tiers');
    if (response.isSuccess && response.data != null) {
      return response.data!
          .map((item) => LoyaltyTierModel.fromJson(item as Map<String, dynamic>))
          .toList();
    }
    return [];
  }

  /// List rewards.
  Future<List<LoyaltyRewardModel>> getRewards() async {
    final response = await apiClient.get<List<dynamic>>('/loyalty/rewards');
    if (response.isSuccess && response.data != null) {
      return response.data!
          .map((item) => LoyaltyRewardModel.fromJson(item as Map<String, dynamic>))
          .toList();
    }
    return [];
  }

  /// Get customer loyalty account overview.
  Future<CustomerLoyaltyAccountModel?> getCustomerAccount(String customerId) async {
    final response = await apiClient.get<Map<String, dynamic>>(
      '/loyalty/customers/$customerId/account',
    );
    if (response.isSuccess && response.data != null) {
      return CustomerLoyaltyAccountModel.fromJson(response.data!);
    }
    return null;
  }

  /// List customer ledger entries.
  Future<List<LoyaltyLedgerItemModel>> getCustomerLedger(
    String customerId, {
    int perPage = 20,
  }) async {
    final response = await apiClient.get<List<dynamic>>(
      '/loyalty/customers/$customerId/ledger?per_page=$perPage',
    );
    if (response.isSuccess && response.data != null) {
      return response.data!
          .map((item) => LoyaltyLedgerItemModel.fromJson(item as Map<String, dynamic>))
          .toList();
    }
    return [];
  }

  /// Redeem a reward for a customer.
  Future<LoyaltyVoucherModel?> redeemReward(
    String customerId,
    String rewardId,
  ) async {
    final response = await apiClient.post<Map<String, dynamic>>(
      '/loyalty/customers/$customerId/redeem',
      body: {'reward_id': rewardId},
    );
    if (response.isSuccess && response.data != null) {
      return LoyaltyVoucherModel.fromJson(response.data!);
    }
    return null;
  }

  /// List customer vouchers.
  Future<List<LoyaltyVoucherModel>> getCustomerVouchers(
    String customerId, {
    String? status,
  }) async {
    final query = status != null ? '?status=$status' : '';
    final response = await apiClient.get<List<dynamic>>(
      '/loyalty/customers/$customerId/vouchers$query',
    );
    if (response.isSuccess && response.data != null) {
      return response.data!
          .map((item) => LoyaltyVoucherModel.fromJson(item as Map<String, dynamic>))
          .toList();
    }
    return [];
  }

  /// Manual points adjustment.
  Future<bool> adjustCustomerPoints(
    String customerId,
    int points,
    String reason,
  ) async {
    final response = await apiClient.post<Map<String, dynamic>>(
      '/loyalty/customers/$customerId/adjust',
      body: {
        'points': points,
        'reason': reason,
      },
    );
    return response.isSuccess;
  }

  /// Server-authoritative voucher validation.
  Future<Map<String, dynamic>?> validateVoucher(
    String code,
    int subtotalMinor, {
    String? customerId,
  }) async {
    final body = <String, dynamic>{
      'code': code,
      'subtotal_minor': subtotalMinor,
    };
    if (customerId != null) {
      body['customer_id'] = customerId;
    }
    final response = await apiClient.post<Map<String, dynamic>>(
      '/loyalty/vouchers/validate',
      body: body,
    );
    if (response.isSuccess && response.data != null) {
      return response.data!;
    }
    return null;
  }

  /// Audit balance reconciliation.
  Future<Map<String, dynamic>?> reconcileAccount(String accountId) async {
    final response = await apiClient.get<Map<String, dynamic>>(
      '/loyalty/reconcile/$accountId',
    );
    if (response.isSuccess && response.data != null) {
      return response.data!;
    }
    return null;
  }
}
