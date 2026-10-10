import 'package:flutter/foundation.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/receipt/receipt_models.dart';
import 'package:uuid/uuid.dart';

class ReceiptService extends ChangeNotifier {
  final ApiClient apiClient;
  static const Uuid _uuid = Uuid();

  bool _isLoading = false;
  String? _errorMessage;

  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  ReceiptService({required this.apiClient});

  void _setLoading(bool value) {
    _isLoading = value;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  Future<ReceiptModel?> getReceipt(String saleId) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.get<dynamic>('/api/v1/pos/sales/$saleId/receipt');
      if (response.isSuccess && response.data != null) {
        return ReceiptModel.fromJson(response.data as Map<String, dynamic>);
      } else {
        _setError(response.message ?? 'Gagal memuat struk transaksi.');
        return null;
      }
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _setLoading(false);
    }
  }

  Future<ReceiptModel?> reprintReceipt(String saleId) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.post<dynamic>(
        '/api/v1/pos/sales/$saleId/receipt/reprint',
        idempotencyKey: _uuid.v7(),
      );
      if (response.isSuccess && response.data != null) {
        return ReceiptModel.fromJson(response.data as Map<String, dynamic>);
      } else {
        _setError(response.message ?? 'Gagal mencetak ulang struk.');
        return null;
      }
    } catch (e) {
      _setError(e.toString());
      return null;
    } finally {
      _setLoading(false);
    }
  }
}
