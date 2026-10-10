import 'package:flutter/foundation.dart';
import '../api/api_client.dart';
import 'payment_models.dart';

class PaymentGatewayService extends ChangeNotifier {
  final ApiClient apiClient;

  List<PaymentProviderDefinition> _platformProviders = [];
  List<TenantPaymentConnection> _tenantConnections = [];
  bool _isLoading = false;
  String? _errorMessage;

  List<PaymentProviderDefinition> get platformProviders => _platformProviders;
  List<TenantPaymentConnection> get tenantConnections => _tenantConnections;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  PaymentGatewayService({required this.apiClient});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Fetch all platform payment providers definitions.
  Future<void> fetchPlatformProviders() async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.get('/platform/payment-providers');
      if (res.isSuccess && res.data is List) {
        _platformProviders = (res.data as List)
            .map((item) =>
                PaymentProviderDefinition.fromJson(item as Map<String, dynamic>))
            .toList();
      } else {
        _setError(res.message ?? 'Failed to load platform payment providers');
      }
    } catch (e) {
      _setError('Error loading platform providers: $e');
    } finally {
      _setLoading(false);
    }
  }

  /// Toggle platform provider state.
  Future<bool> togglePlatformProvider(String provider, bool enable) async {
    _setLoading(true);
    _setError(null);

    try {
      final action = enable ? 'enable' : 'disable';
      final res = await apiClient.post('/platform/payment-providers/$provider/$action');

      if (res.isSuccess) {
        await fetchPlatformProviders();
        return true;
      } else {
        _setError(res.message ?? 'Failed to update provider status');
        return false;
      }
    } catch (e) {
      _setError('Error updating provider status: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Fetch gateway connections for a tenant.
  Future<void> fetchTenantConnections(String tenantId) async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.get('/platform/tenants/$tenantId/payment-connections');
      if (res.isSuccess && res.data is List) {
        _tenantConnections = (res.data as List)
            .map((item) =>
                TenantPaymentConnection.fromJson(item as Map<String, dynamic>))
            .toList();
      } else {
        _setError(res.message ?? 'Failed to load tenant payment connections');
      }
    } catch (e) {
      _setError('Error loading tenant connections: $e');
    } finally {
      _setLoading(false);
    }
  }

  /// Save or update a tenant gateway connection.
  Future<bool> saveTenantConnection({
    required String tenantId,
    required String provider,
    required String environment,
    required String merchantIdentifier,
    required Map<String, dynamic> credentials,
    bool isDefault = false,
    String status = 'active',
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.put(
        '/platform/tenants/$tenantId/payment-connections/$provider',
        body: {
          'environment': environment,
          'merchant_identifier': merchantIdentifier,
          'credentials': credentials,
          'is_default': isDefault,
          'status': status,
        },
      );

      if (res.isSuccess) {
        await fetchTenantConnections(tenantId);
        return true;
      } else {
        _setError(res.message ?? 'Failed to save gateway connection');
        return false;
      }
    } catch (e) {
      _setError('Error saving gateway connection: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Trigger safe non-destructive connection verification.
  Future<Map<String, dynamic>?> verifyTenantConnection({
    required String tenantId,
    required String provider,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.post(
        '/platform/tenants/$tenantId/payment-connections/$provider/verify',
      );

      if (res.isSuccess && res.data is Map<String, dynamic>) {
        await fetchTenantConnections(tenantId);
        return res.data as Map<String, dynamic>;
      } else {
        _setError(res.message ?? 'Verification failed');
        return null;
      }
    } catch (e) {
      _setError('Error verifying connection: $e');
      return null;
    } finally {
      _setLoading(false);
    }
  }
}
