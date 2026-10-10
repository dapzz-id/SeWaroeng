import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../api/api_client.dart';
import '../auth/token_storage.dart';
import 'outlet_model.dart';
import 'tenant_model.dart';

class TenantService extends ChangeNotifier {
  static final _uuid = const Uuid();

  final ApiClient apiClient;
  final TokenStorage tokenStorage;

  List<TenantModel> _businesses = [];
  TenantModel? _activeTenant;
  List<OutletModel> _outlets = [];
  OutletModel? _activeOutlet;

  bool _isLoading = false;
  String? _errorMessage;

  List<TenantModel> get businesses => _businesses;
  TenantModel? get activeTenant => _activeTenant;
  List<OutletModel> get outlets => _outlets;
  OutletModel? get activeOutlet => _activeOutlet;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  TenantService({required this.apiClient, required this.tokenStorage});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Onboard / Create a new business and first outlet atomically.
  Future<bool> createBusiness({
    required String name,
    String? slug,
    String? businessType = 'fnb',
    String? outletName,
    String? outletCode,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/tenants',
        idempotencyKey: key,
        body: {
          'name': name.trim(),
          if (slug != null && slug.trim().isNotEmpty) 'slug': slug.trim(),
          'business_type': businessType ?? 'fnb',
          if (outletName != null && outletName.trim().isNotEmpty)
            'outlet_name': outletName.trim(),
          if (outletCode != null && outletCode.trim().isNotEmpty)
            'outlet_code': outletCode.trim().toUpperCase(),
        },
      );

      if (response.isSuccess && response.data != null) {
        final data = response.data!;
        final tenantJson = data['tenant'] as Map<String, dynamic>;
        final outletJson = data['outlet'] as Map<String, dynamic>;

        final newTenant = TenantModel.fromJson(tenantJson);
        final newOutlet = OutletModel.fromJson(outletJson);

        _businesses.add(newTenant);
        await setActiveTenant(newTenant, reloadOutlets: false);
        _outlets = [newOutlet];
        _activeOutlet = newOutlet;
        await tokenStorage.saveActiveOutletId(newOutlet.id);

        _setLoading(false);
        return true;
      }

      final errorMsg = _mapErrorMessage(response.code, response.message);
      _setError(errorMsg);
      _setLoading(false);
      return false;
    } catch (_) {
      _setError('Gagal menghubungi server. Periksa koneksi internet Anda.');
      _setLoading(false);
      return false;
    }
  }

  /// Load businesses where authenticated user is an active member.
  Future<void> loadBusinesses() async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.get<List<dynamic>>('/api/v1/tenants');

      if (response.isSuccess && response.data != null) {
        _businesses = response.data!
            .map((item) => TenantModel.fromJson(item as Map<String, dynamic>))
            .toList();

        // Restore active tenant context from storage
        final storedTenantId = await tokenStorage.getActiveTenantId();
        if (storedTenantId != null) {
          final matched = _businesses
              .where((b) => b.id == storedTenantId)
              .firstOrNull;
          if (matched != null) {
            await setActiveTenant(matched, reloadOutlets: true);
          } else if (_businesses.isNotEmpty) {
            await setActiveTenant(_businesses.first, reloadOutlets: true);
          } else {
            await clearContext();
          }
        } else if (_businesses.isNotEmpty) {
          await setActiveTenant(_businesses.first, reloadOutlets: true);
        }
      }
    } catch (_) {
      // In offline / error state, keep existing in-memory context
    } finally {
      _setLoading(false);
    }
  }

  /// Fetch full details for a business.
  Future<TenantModel?> getBusinessDetail(String tenantId) async {
    try {
      final response = await apiClient.get<Map<String, dynamic>>(
        '/api/v1/tenants/$tenantId',
      );
      if (response.isSuccess && response.data != null) {
        final updated = TenantModel.fromJson(response.data!);
        if (_activeTenant?.id == tenantId) {
          _activeTenant = updated;
          notifyListeners();
        }
        return updated;
      }
    } catch (_) {}
    return null;
  }

  /// Update business profile (Owner or business.update permission).
  Future<bool> updateBusiness({
    required String tenantId,
    required Map<String, dynamic> data,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final response = await apiClient.patch<Map<String, dynamic>>(
        '/api/v1/tenants/$tenantId',
        idempotencyKey: key,
        body: data,
      );

      if (response.isSuccess && response.data != null) {
        final updatedTenant = TenantModel.fromJson(response.data!);

        final index = _businesses.indexWhere((b) => b.id == tenantId);
        if (index != -1) {
          _businesses[index] = updatedTenant;
        }

        if (_activeTenant?.id == tenantId) {
          _activeTenant = updatedTenant;
        }

        _setLoading(false);
        return true;
      }

      final errorMsg = _mapErrorMessage(response.code, response.message);
      _setError(errorMsg);
      _setLoading(false);
      return false;
    } catch (_) {
      _setError(
        'Gagal memperbarui profil bisnis. Periksa koneksi internet Anda.',
      );
      _setLoading(false);
      return false;
    }
  }

  /// Switch active business context.
  /// Strictly prevents retaining an active outlet from the previous tenant!
  Future<void> setActiveTenant(
    TenantModel tenant, {
    bool reloadOutlets = true,
  }) async {
    _activeTenant = tenant;
    apiClient.activeTenantId = tenant.id;
    await tokenStorage.saveActiveTenantId(tenant.id);

    // CRITICAL: Immediately clear invalid outlet from previous business
    _activeOutlet = null;
    await tokenStorage.saveActiveOutletId(null);
    notifyListeners();

    if (reloadOutlets) {
      await loadOutlets();
    }
  }

  /// Set outlets directly for testing.
  void setOutletsForTesting(List<OutletModel> outlets) {
    _outlets = List<OutletModel>.from(outlets);
    notifyListeners();
  }

  /// Alias for loading outlets.
  Future<void> fetchOutlets([String? tenantId]) async {
    await loadOutlets(includeInactive: true);
  }

  /// Load outlets for current active business context.
  Future<void> loadOutlets({bool includeInactive = false}) async {
    if (_activeTenant == null) return;

    try {
      final path = includeInactive
          ? '/api/v1/outlets?include_inactive=1'
          : '/api/v1/outlets';
      final response = await apiClient.get<List<dynamic>>(path);

      if (response.isSuccess && response.data != null) {
        _outlets = response.data!
            .map((item) => OutletModel.fromJson(item as Map<String, dynamic>))
            .toList();

        // Restore active outlet from storage if still valid for this tenant
        final storedOutletId = await tokenStorage.getActiveOutletId();
        if (storedOutletId != null) {
          final matched = _outlets
              .where(
                (o) =>
                    o.id == storedOutletId && o.tenantId == _activeTenant!.id,
              )
              .firstOrNull;
          _activeOutlet = matched ?? _outlets.firstOrNull;
        } else {
          _activeOutlet = _outlets.firstOrNull;
        }

        if (_activeOutlet != null) {
          await tokenStorage.saveActiveOutletId(_activeOutlet!.id);
        }
        notifyListeners();
      }
    } catch (_) {
      // Non-blocking in offline state
    }
  }

  /// Select active outlet (enforcing that it belongs to active tenant).
  Future<bool> setActiveOutlet(OutletModel outlet) async {
    if (_activeTenant == null || outlet.tenantId != _activeTenant!.id) {
      return false;
    }

    _activeOutlet = outlet;
    await tokenStorage.saveActiveOutletId(outlet.id);
    notifyListeners();
    return true;
  }

  /// Create an additional outlet in current tenant context.
  Future<bool> createOutlet({
    String? tenantId,
    required String name,
    required String code,
    String? address,
    String? addressLine,
    String? city,
    String? province,
    String? postalCode,
    String? phone,
    String? phoneNumber,
    double? latitude,
    double? longitude,
    String? timezone,
    Map<String, dynamic>? operatingHours,
    String? idempotencyKey,
  }) async {
    if (_activeTenant == null) return false;

    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();
    final effectivePhone = phone ?? phoneNumber;

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/outlets',
        idempotencyKey: key,
        body: {
          'name': name.trim(),
          'code': code.trim().toUpperCase(),
          if (address != null) 'address': address.trim(),
          if (addressLine != null) 'address_line': addressLine.trim(),
          if (city != null) 'city': city.trim(),
          if (province != null) 'province': province.trim(),
          if (postalCode != null) 'postal_code': postalCode.trim(),
          if (effectivePhone != null) 'phone': effectivePhone.trim(),
          'latitude': ?latitude,
          'longitude': ?longitude,
          'timezone': ?timezone,
          'operating_hours': ?operatingHours,
        },
      );

      if (response.isSuccess && response.data != null) {
        final newOutlet = OutletModel.fromJson(response.data!);
        _outlets.add(newOutlet);
        if (_activeOutlet == null) {
          await setActiveOutlet(newOutlet);
        }
        _setLoading(false);
        return true;
      }

      final errorMsg = _mapErrorMessage(response.code, response.message);
      _setError(errorMsg);
      _setLoading(false);
      return false;
    } catch (_) {
      _setError('Gagal membuat outlet. Periksa koneksi internet Anda.');
      _setLoading(false);
      return false;
    }
  }

  /// Update an existing outlet in current tenant context.
  Future<bool> updateOutlet({
    required String outletId,
    required Map<String, dynamic> data,
    String? idempotencyKey,
  }) async {
    if (_activeTenant == null) return false;

    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final response = await apiClient.patch<Map<String, dynamic>>(
        '/api/v1/outlets/$outletId',
        idempotencyKey: key,
        body: data,
      );

      if (response.isSuccess && response.data != null) {
        final updatedOutlet = OutletModel.fromJson(response.data!);

        final index = _outlets.indexWhere((o) => o.id == outletId);
        if (index != -1) {
          _outlets[index] = updatedOutlet;
        }

        if (_activeOutlet?.id == outletId) {
          _activeOutlet = updatedOutlet;
        }

        _setLoading(false);
        return true;
      }

      final errorMsg = _mapErrorMessage(response.code, response.message);
      _setError(errorMsg);
      _setLoading(false);
      return false;
    } catch (_) {
      _setError('Gagal memperbarui outlet. Periksa koneksi internet Anda.');
      _setLoading(false);
      return false;
    }
  }

  /// Toggle outlet status (activate / deactivate).
  Future<bool> toggleOutletStatus({
    required String outletId,
    required String status,
  }) async {
    return updateOutlet(outletId: outletId, data: {'status': status});
  }

  /// Clear all business and outlet context (on logout).
  Future<void> clearContext() async {
    _activeTenant = null;
    _activeOutlet = null;
    _businesses = [];
    _outlets = [];
    apiClient.activeTenantId = null;
    await tokenStorage.saveActiveTenantId(null);
    await tokenStorage.saveActiveOutletId(null);
    notifyListeners();
  }

  String _mapErrorMessage(String? code, String? fallback) {
    return switch (code) {
      'BUSINESS_SLUG_TAKEN' =>
        'Slug bisnis ini sudah digunakan. Harap gunakan nama atau slug lain.',
      'BUSINESS_SLUG_INVALID' =>
        'Format slug tidak valid atau merupakan nama yang dicadangkan.',
      'BUSINESS_NAME_REQUIRED' => 'Nama bisnis wajib diisi.',
      'OUTLET_CODE_TAKEN' => 'Kode outlet sudah terdaftar di bisnis ini.',
      'TENANT_ACCESS_DENIED' =>
        'Akses ke bisnis ini ditolak atau bisnis dinonaktifkan.',
      'BUSINESS_SUSPENDED' => 'Bisnis ini sedang ditangguhkan atau diarsipkan.',
      'FORBIDDEN_PERMISSION' =>
        'Anda tidak memiliki hak akses untuk melakukan tindakan ini.',
      _ => fallback ?? 'Terjadi kesalahan pada sistem. Silakan coba kembali.',
    };
  }
}
