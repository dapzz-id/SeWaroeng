import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../api/api_client.dart';
import 'employee_models.dart';

class EmployeeService extends ChangeNotifier {
  static final _uuid = const Uuid();

  final ApiClient apiClient;

  List<EmployeeModel> _employees = [];
  List<EmployeeRoleModel> _availableRoles = [];
  bool _isLoading = false;
  String? _errorMessage;

  List<EmployeeModel> get employees => _employees;
  List<EmployeeRoleModel> get availableRoles => _availableRoles;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  EmployeeService({required this.apiClient});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Load roles and employees list
  Future<void> fetchEmployees({String? role, String? status, String? search}) async {
    _setLoading(true);
    _setError(null);

    try {
      final queryParams = <String, String>{};
      if (role != null && role.isNotEmpty) queryParams['role'] = role;
      if (status != null && status.isNotEmpty) queryParams['status'] = status;
      if (search != null && search.isNotEmpty) queryParams['search'] = search;

      final uri = Uri(
        path: '/api/v1/employees',
        queryParameters: queryParams.isEmpty ? null : queryParams,
      );

      final response = await apiClient.get<List<dynamic>>(uri.toString());

      if (response.isSuccess && response.data != null) {
        _employees = response.data!
            .map((e) => EmployeeModel.fromJson(e as Map<String, dynamic>))
            .toList();
      } else {
        _setError(response.message ?? 'Gagal memuat daftar karyawan');
      }
    } catch (e) {
      _setError(e.toString());
    } finally {
      _setLoading(false);
    }
  }

  /// Fetch system assignable roles
  Future<void> fetchRoles() async {
    try {
      final response = await apiClient.get<List<dynamic>>('/api/v1/employees/roles');
      if (response.isSuccess && response.data != null) {
        _availableRoles = response.data!
            .map((e) => EmployeeRoleModel.fromJson(e as Map<String, dynamic>))
            .toList();
        notifyListeners();
      }
    } catch (e) {
      debugPrint('Failed to load employee roles: $e');
    }
  }

  /// Add/invite a new employee
  Future<bool> addEmployee({
    required String name,
    required String email,
    String? phoneNumber,
    String? password,
    required String roleSlug,
    String outletAccess = 'all',
    List<String> assignedOutletIds = const [],
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/employees',
        idempotencyKey: key,
        body: {
          'name': name.trim(),
          'email': email.trim().toLowerCase(),
          if (phoneNumber != null && phoneNumber.isNotEmpty)
            'phone_number': phoneNumber.trim(),
          if (password != null && password.isNotEmpty) 'password': password,
          'role_slug': roleSlug,
          'outlet_access': outletAccess,
          'assigned_outlet_ids': assignedOutletIds,
        },
      );

      if (response.isSuccess) {
        await fetchEmployees();
        return true;
      } else {
        _setError(response.message ?? 'Gagal menambahkan karyawan');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Update employee role, outlets, or status
  Future<bool> updateEmployee({
    required String membershipId,
    String? name,
    String? phoneNumber,
    String? roleSlug,
    String? status,
    String? outletAccess,
    List<String>? assignedOutletIds,
    String? idempotencyKey,
  }) async {
    _setLoading(true);
    _setError(null);

    final key = idempotencyKey ?? _uuid.v7();

    try {
      final body = <String, dynamic>{};
      if (name != null) body['name'] = name.trim();
      if (phoneNumber != null) body['phone_number'] = phoneNumber.trim();
      if (roleSlug != null) body['role_slug'] = roleSlug;
      if (status != null) body['status'] = status;
      if (outletAccess != null) body['outlet_access'] = outletAccess;
      if (assignedOutletIds != null) {
        body['assigned_outlet_ids'] = assignedOutletIds;
      }

      final response = await apiClient.patch<Map<String, dynamic>>(
        '/api/v1/employees/$membershipId',
        idempotencyKey: key,
        body: body,
      );

      if (response.isSuccess) {
        await fetchEmployees();
        return true;
      } else {
        _setError(response.message ?? 'Gagal memperbarui karyawan');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Delete / revoke employee membership
  Future<bool> removeEmployee(String membershipId) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.delete<Map<String, dynamic>>(
        '/api/v1/employees/$membershipId',
      );

      if (response.isSuccess) {
        await fetchEmployees();
        return true;
      } else {
        _setError(response.message ?? 'Gagal menghapus karyawan');
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
