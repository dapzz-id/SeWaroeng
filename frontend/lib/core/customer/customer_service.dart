import 'package:flutter/foundation.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/customer/customer_models.dart';
import 'package:uuid/uuid.dart';

class CustomerService extends ChangeNotifier {
  final ApiClient apiClient;
  static const Uuid _uuid = Uuid();

  List<CustomerModel> _customers = [];
  bool _isLoading = false;
  String? _errorMessage;

  List<CustomerModel> get customers => _customers;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  CustomerService({required this.apiClient});

  void _setLoading(bool value) {
    _isLoading = value;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  Future<List<CustomerModel>> fetchCustomers({
    String? search,
    int page = 1,
    int perPage = 20,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final queryParams = <String>[];
      if (search != null && search.trim().isNotEmpty) {
        queryParams.add('search=${Uri.encodeComponent(search.trim())}');
      }
      queryParams.add('page=$page');
      queryParams.add('per_page=$perPage');

      final queryStr = queryParams.isNotEmpty ? '?${queryParams.join('&')}' : '';
      final response = await apiClient.get<dynamic>('/api/v1/customers$queryStr');

      if (response.isSuccess && response.data != null) {
        final data = response.data;
        List<dynamic> list = [];
        if (data is List) {
          list = data;
        } else if (data is Map && data['data'] is List) {
          list = data['data'] as List;
        }

        _customers = list
            .map((c) => CustomerModel.fromJson(c as Map<String, dynamic>))
            .toList();
        return _customers;
      } else {
        _setError(response.message ?? 'Gagal memuat data pelanggan.');
        return [];
      }
    } catch (e) {
      _setError(e.toString());
      return [];
    } finally {
      _setLoading(false);
    }
  }

  Future<CustomerModel?> createCustomer({
    required String name,
    String? phone,
    String? email,
    String? notes,
    bool marketingOptIn = false,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final payload = <String, dynamic>{
        'name': name.trim(),
        'marketing_opt_in': marketingOptIn,
      };
      if (phone != null && phone.trim().isNotEmpty) {
        payload['phone'] = phone.trim();
      }
      if (email != null && email.trim().isNotEmpty) {
        payload['email'] = email.trim();
      }
      if (notes != null && notes.trim().isNotEmpty) {
        payload['notes'] = notes.trim();
      }

      final response = await apiClient.post<dynamic>(
        '/api/v1/customers',
        idempotencyKey: _uuid.v7(),
        body: payload,
      );

      if (response.isSuccess && response.data != null) {
        final newCustomer = CustomerModel.fromJson(response.data as Map<String, dynamic>);
        _customers.insert(0, newCustomer);
        notifyListeners();
        return newCustomer;
      } else {
        _setError(response.message ?? 'Gagal membuat pelanggan.');
        return null;
      }
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _setLoading(false);
    }
  }

  Future<CustomerModel?> updateCustomer(
    String id, {
    required String name,
    String? phone,
    String? email,
    String? notes,
    bool? isActive,
    bool? marketingOptIn,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final payload = <String, dynamic>{
        'name': name.trim(),
      };
      if (phone != null) payload['phone'] = phone.trim().isEmpty ? null : phone.trim();
      if (email != null) payload['email'] = email.trim().isEmpty ? null : email.trim();
      if (notes != null) payload['notes'] = notes.trim();
      if (isActive != null) payload['is_active'] = isActive;
      if (marketingOptIn != null) payload['marketing_opt_in'] = marketingOptIn;

      final response = await apiClient.put<dynamic>(
        '/api/v1/customers/$id',
        idempotencyKey: _uuid.v7(),
        body: payload,
      );

      if (response.isSuccess && response.data != null) {
        final updatedCustomer = CustomerModel.fromJson(response.data as Map<String, dynamic>);
        final index = _customers.indexWhere((c) => c.id == id);
        if (index >= 0) {
          _customers[index] = updatedCustomer;
        }
        notifyListeners();
        return updatedCustomer;
      } else {
        _setError(response.message ?? 'Gagal memperbarui pelanggan.');
        return null;
      }
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _setLoading(false);
    }
  }

  Future<CustomerModel?> getCustomer(String id) async {
    try {
      final response = await apiClient.get<dynamic>('/api/v1/customers/$id');
      if (response.isSuccess && response.data != null) {
        return CustomerModel.fromJson(response.data as Map<String, dynamic>);
      }
      return null;
    } catch (_) {
      return null;
    }
  }

  Future<List<CustomerHistoryItemModel>> getCustomerHistory(String id, {int page = 1}) async {
    try {
      final response = await apiClient.get<dynamic>('/api/v1/customers/$id/history?page=$page');
      if (response.isSuccess && response.data != null) {
        final data = response.data;
        List<dynamic> list = [];
        if (data is List) {
          list = data;
        } else if (data is Map && data['data'] is List) {
          list = data['data'] as List;
        }
        return list.map((item) => CustomerHistoryItemModel.fromJson(item as Map<String, dynamic>)).toList();
      }
      return [];
    } catch (_) {
      return [];
    }
  }

  Future<CustomerSummaryModel?> getCustomerSummary(String id) async {
    try {
      final response = await apiClient.get<dynamic>('/api/v1/customers/$id/summary');
      if (response.isSuccess && response.data != null) {
        return CustomerSummaryModel.fromJson(response.data as Map<String, dynamic>);
      }
      return null;
    } catch (_) {
      return null;
    }
  }
}
