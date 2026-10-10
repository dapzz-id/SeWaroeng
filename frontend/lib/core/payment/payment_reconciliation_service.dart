import 'package:flutter/foundation.dart';
import '../api/api_client.dart';
import 'payment_reconciliation_models.dart';

class PaymentReconciliationService extends ChangeNotifier {
  final ApiClient apiClient;

  List<PaymentReconciliationModel> _items = [];
  ReconciliationSummaryModel _summary = ReconciliationSummaryModel.empty();
  bool _isLoading = false;
  String? _errorMessage;

  List<PaymentReconciliationModel> get items => _items;
  ReconciliationSummaryModel get summary => _summary;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  PaymentReconciliationService({required this.apiClient});

  void _setLoading(bool value) {
    _isLoading = value;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Fetch paginated reconciliation records with optional filters.
  Future<List<PaymentReconciliationModel>> fetchReconciliations({
    String? outletId,
    String? status,
    String? provider,
    String? startDate,
    String? endDate,
    int page = 1,
    int perPage = 20,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final params = <String>[];
      if (outletId != null && outletId.isNotEmpty) {
        params.add('outlet_id=$outletId');
      }
      if (status != null && status.isNotEmpty) params.add('status=$status');
      if (provider != null && provider.isNotEmpty) {
        params.add('provider=$provider');
      }
      if (startDate != null && startDate.isNotEmpty) {
        params.add('start_date=$startDate');
      }
      if (endDate != null && endDate.isNotEmpty) {
        params.add('end_date=$endDate');
      }
      params.add('page=$page');
      params.add('per_page=$perPage');

      final queryStr = params.isNotEmpty ? '?${params.join('&')}' : '';
      final response = await apiClient
          .get<dynamic>('/api/v1/payment-reconciliation$queryStr');

      if (response.isSuccess && response.data != null) {
        final dynamic rawList = response.data is Map &&
                (response.data as Map<String, dynamic>).containsKey('data')
            ? (response.data as Map<String, dynamic>)['data']
            : response.data;

        if (rawList is List) {
          _items = rawList
              .map((item) => PaymentReconciliationModel.fromJson(
                  item as Map<String, dynamic>))
              .toList();
          return _items;
        }
      }
      _setError(response.message ?? 'Gagal memuat data rekonsiliasi.');
      return [];
    } catch (e) {
      _setError('Terjadi kesalahan memuat data rekonsiliasi: $e');
      return [];
    } finally {
      _setLoading(false);
    }
  }

  /// Fetch aggregate summary metrics for active tenant / outlet.
  Future<ReconciliationSummaryModel?> fetchSummary({String? outletId}) async {
    try {
      final queryStr = (outletId != null && outletId.isNotEmpty)
          ? '?outlet_id=$outletId'
          : '';
      final response = await apiClient
          .get<dynamic>('/api/v1/payment-reconciliation/summary$queryStr');

      if (response.isSuccess && response.data is Map<String, dynamic>) {
        _summary = ReconciliationSummaryModel.fromJson(
            response.data as Map<String, dynamic>);
        notifyListeners();
        return _summary;
      }
      return null;
    } catch (e) {
      _setError('Terjadi kesalahan memuat ringkasan rekonsiliasi: $e');
      return null;
    }
  }

  /// Resolve discrepancy with resolution notes.
  Future<bool> resolveDiscrepancy(String id, String resolutionNotes) async {
    _setLoading(true);
    _setError(null);

    try {
      final response = await apiClient.post<dynamic>(
        '/api/v1/payment-reconciliation/$id/resolve',
        body: {'resolution_notes': resolutionNotes},
      );

      if (response.isSuccess && response.data is Map<String, dynamic>) {
        final updated = PaymentReconciliationModel.fromJson(
            response.data as Map<String, dynamic>);
        final idx = _items.indexWhere((e) => e.id == id);
        if (idx != -1) {
          _items[idx] = updated;
        }
        await fetchSummary();
        notifyListeners();
        return true;
      } else {
        _setError(response.message ?? 'Gagal menyelesaikan selisih.');
        return false;
      }
    } catch (e) {
      _setError('Terjadi kesalahan menyelesaikan selisih: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Ingest batch of provider statement rows.
  Future<Map<String, dynamic>?> importBatch(
    List<Map<String, dynamic>> items, {
    String? outletId,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final payload = <String, dynamic>{'items': items};
      if (outletId != null && outletId.isNotEmpty) {
        payload['outlet_id'] = outletId;
      }

      final response = await apiClient.post<dynamic>(
        '/api/v1/payment-reconciliation/import',
        body: payload,
      );

      if (response.isSuccess && response.data is Map<String, dynamic>) {
        await fetchReconciliations(outletId: outletId);
        await fetchSummary(outletId: outletId);
        return response.data as Map<String, dynamic>;
      } else {
        _setError(response.message ?? 'Gagal mengimpor batch rekonsiliasi.');
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan mengimpor data: $e');
      return null;
    } finally {
      _setLoading(false);
    }
  }
}
