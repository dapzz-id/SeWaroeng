import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../api/api_client.dart';
import 'order_models.dart';

class OrderService extends ChangeNotifier {
  static final _uuid = const Uuid();

  final ApiClient apiClient;

  List<OrderModel> _orders = [];
  bool _isLoading = false;
  String? _errorMessage;

  List<OrderModel> get orders => _orders;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  OrderService({required this.apiClient});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Fetch orders for active outlet
  Future<void> fetchOrders({String? outletId, String? status}) async {
    _setLoading(true);
    _setError(null);

    try {
      final queryParams = <String, String>{};
      if (outletId != null && outletId.isNotEmpty) {
        queryParams['outlet_id'] = outletId;
      }
      if (status != null && status.isNotEmpty) {
        queryParams['status'] = status;
      }

      final uri = Uri(
        path: '/api/v1/orders',
        queryParameters: queryParams.isEmpty ? null : queryParams,
      );

      final response = await apiClient.get<List<dynamic>>(uri.toString());

      if (response.isSuccess && response.data != null) {
        _orders = response.data!
            .map((e) => OrderModel.fromJson(e as Map<String, dynamic>))
            .toList();
      } else {
        _setError(response.message ?? 'Gagal memuat daftar pesanan.');
      }
    } catch (e) {
      _setError(e.toString());
    } finally {
      _setLoading(false);
    }
  }

  /// Update order status
  Future<bool> updateOrderStatus(
    String orderId,
    String newStatus, {
    String? outletId,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.patch<Map<String, dynamic>>(
        '/api/v1/orders/$orderId/status',
        idempotencyKey: _uuid.v7(),
        body: {'status': newStatus},
      );

      if (response.isSuccess) {
        await fetchOrders(outletId: outletId);
        return true;
      } else {
        _setError(response.message ?? 'Gagal mengubah status pesanan.');
        return false;
      }
    } catch (e) {
      _setError(e.toString());
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Convert orders to POS Sale
  Future<bool> convertToSale({
    required String outletId,
    required List<String> orderIds,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/orders/convert-to-sale',
        idempotencyKey: _uuid.v7(),
        body: {
          'outlet_id': outletId,
          'order_ids': orderIds,
        },
      );

      if (response.isSuccess) {
        await fetchOrders(outletId: outletId);
        return true;
      } else {
        _setError(response.message ?? 'Gagal mengonversi pesanan ke penjualan POS.');
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
