import 'package:flutter/foundation.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/subscription/subscription_models.dart';

class SubscriptionService extends ChangeNotifier {
  final ApiClient apiClient;

  TenantSubscription? _subscription;
  SubscriptionUsage? _usage;
  SubscriptionEntitlements? _entitlements;
  bool _isLoading = false;
  String? _errorMessage;

  TenantSubscription? get subscription => _subscription;
  SubscriptionUsage? get usage => _usage;
  SubscriptionEntitlements? get entitlements => _entitlements;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  SubscriptionService({required this.apiClient});

  void _setLoading(bool value) {
    _isLoading = value;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Fetches tenant subscription overview
  Future<TenantSubscription?> fetchSubscription() async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.get<dynamic>('/api/v1/subscription');

      if (response.isSuccess && response.data != null) {
        final dynamic raw = response.data;
        Map<String, dynamic> dataMap = {};
        if (raw is Map && raw.containsKey('data') && raw['data'] is Map) {
          dataMap = Map<String, dynamic>.from(raw['data'] as Map);
        } else if (raw is Map) {
          dataMap = Map<String, dynamic>.from(raw);
        }

        _subscription = TenantSubscription.fromJson(dataMap);
        notifyListeners();
        return _subscription;
      } else {
        _setError(response.message ?? 'Gagal memuat status langganan');
        return null;
      }
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _setLoading(false);
    }
  }

  /// Fetches authoritative quota usage
  Future<SubscriptionUsage?> fetchUsage() async {
    try {
      final response = await apiClient.get<dynamic>('/api/v1/subscription/usage');

      if (response.isSuccess && response.data != null) {
        final dynamic raw = response.data;
        Map<String, dynamic> dataMap = {};
        if (raw is Map && raw.containsKey('data') && raw['data'] is Map) {
          dataMap = Map<String, dynamic>.from(raw['data'] as Map);
        } else if (raw is Map) {
          dataMap = Map<String, dynamic>.from(raw);
        }

        _usage = SubscriptionUsage.fromJson(dataMap);
        notifyListeners();
        return _usage;
      }
      return null;
    } catch (e) {
      _setError(e.toString());
      return null;
    }
  }

  /// Fetches active entitlements
  Future<SubscriptionEntitlements?> fetchEntitlements() async {
    try {
      final response = await apiClient.get<dynamic>('/api/v1/subscription/entitlements');

      if (response.isSuccess && response.data != null) {
        final dynamic raw = response.data;
        Map<String, dynamic> dataMap = {};
        if (raw is Map && raw.containsKey('data') && raw['data'] is Map) {
          dataMap = Map<String, dynamic>.from(raw['data'] as Map);
        } else if (raw is Map) {
          dataMap = Map<String, dynamic>.from(raw);
        }

        _entitlements = SubscriptionEntitlements.fromJson(dataMap);
        notifyListeners();
        return _entitlements;
      }
      return null;
    } catch (e) {
      _setError(e.toString());
      return null;
    }
  }

  /// Load all subscription information at once
  Future<void> loadSubscriptionData() async {
    _setLoading(true);
    _setError(null);
    try {
      await Future.wait([
        fetchSubscription(),
        fetchUsage(),
        fetchEntitlements(),
      ]);
    } finally {
      _setLoading(false);
    }
  }

  /// Cancels tenant subscription
  Future<bool> cancelSubscription() async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.post<dynamic>(
        '/api/v1/subscription/cancel',
        body: {},
      );

      if (response.isSuccess) {
        await loadSubscriptionData();
        return true;
      } else {
        _setError(response.message ?? 'Gagal membatalkan langganan');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }
}
