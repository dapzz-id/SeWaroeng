import 'package:flutter/foundation.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/dashboard/dashboard_models.dart';

class DashboardService extends ChangeNotifier {
  final ApiClient apiClient;

  DashboardData? _dashboardData;
  bool _isLoading = false;
  String? _errorMessage;
  String _selectedPeriod = 'today';
  String? _selectedOutletId;

  DashboardData? get dashboardData => _dashboardData;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;
  String get selectedPeriod => _selectedPeriod;
  String? get selectedOutletId => _selectedOutletId;

  DashboardService({required this.apiClient});

  void _setLoading(bool value) {
    _isLoading = value;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Fetch dashboard data with filters
  Future<DashboardData?> fetchDashboard({
    String? period,
    String? outletId,
    String? startDate,
    String? endDate,
  }) async {
    _setLoading(true);
    _setError(null);

    if (period != null) _selectedPeriod = period;
    _selectedOutletId = outletId;

    try {
      final params = <String>[];
      params.add('period=$_selectedPeriod');
      if (outletId != null && outletId.isNotEmpty) {
        params.add('outlet_id=$outletId');
      }
      if (startDate != null && startDate.isNotEmpty) {
        params.add('start_date=$startDate');
      }
      if (endDate != null && endDate.isNotEmpty) {
        params.add('end_date=$endDate');
      }

      final queryStr = '?${params.join('&')}';
      final response = await apiClient.get<dynamic>('/api/v1/dashboard$queryStr');

      if (response.isSuccess && response.data != null) {
        final dynamic raw = response.data;
        Map<String, dynamic> dataMap = {};
        if (raw is Map && raw.containsKey('data') && raw['data'] is Map) {
          dataMap = Map<String, dynamic>.from(raw['data'] as Map);
        } else if (raw is Map) {
          dataMap = Map<String, dynamic>.from(raw);
        }

        _dashboardData = DashboardData.fromJson(dataMap);
        _setLoading(false);
        return _dashboardData;
      } else {
        _setError(response.message ?? 'Gagal memuat data dashboard.');
        _setLoading(false);
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan saat memuat dashboard: $e');
      _setLoading(false);
      return null;
    }
  }
}
