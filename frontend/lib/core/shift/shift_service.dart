import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';
import '../api/api_client.dart';
import 'shift_models.dart';

/// Shift and Cash Drawer Management Service.
///
/// ARCHITECTURAL POLICY:
/// Cash drawer mutations (open, cash in, cash out, adjustment, close)
/// are strictly ONLINE-ONLY. Server is the single source of truth for
/// drawer balances, expected cash, and variance calculations.
class ShiftService extends ChangeNotifier {
  static const Uuid _uuid = Uuid();
  final ApiClient apiClient;

  ShiftModel? _currentShift;
  CashSummaryModel? _currentSummary;
  List<CashMovementModel> _movements = [];
  bool _isLoading = false;
  String? _errorMessage;

  ShiftModel? get currentShift => _currentShift;
  CashSummaryModel? get currentSummary => _currentSummary;
  List<CashMovementModel> get movements => _movements;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;
  bool get hasActiveShift => _currentShift != null && _currentShift!.isOpen;

  ShiftService({required this.apiClient});

  void _setLoading(bool loading) {
    _isLoading = loading;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Fetches the currently active shift and cash summary for the given outlet.
  Future<void> fetchCurrentShift(String outletId) async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.get('/outlets/$outletId/shifts/current');
      if (res.isSuccess) {
        if (res.data != null && res.data is Map<String, dynamic>) {
          final data = res.data as Map<String, dynamic>;
          if (data['shift'] != null) {
            _currentShift = ShiftModel.fromJson(data['shift'] as Map<String, dynamic>);
          } else {
            _currentShift = null;
          }
          if (data['summary'] != null) {
            _currentSummary = CashSummaryModel.fromJson(data['summary'] as Map<String, dynamic>);
          } else {
            _currentSummary = null;
          }
        } else {
          _currentShift = null;
          _currentSummary = null;
        }
      } else {
        _setError(res.message ?? 'Gagal memuat status shift');
      }
    } catch (e) {
      _setError('Error memuat shift: $e');
    } finally {
      _setLoading(false);
    }
  }

  /// Refreshes authoritative cash summary for a shift.
  Future<void> refreshSummary(String shiftId) async {
    _setLoading(true);
    _setError(null);

    try {
      final res = await apiClient.get('/shifts/$shiftId/summary');
      if (res.isSuccess && res.data is Map<String, dynamic>) {
        final data = res.data as Map<String, dynamic>;
        if (data['shift'] != null) {
          _currentShift = ShiftModel.fromJson(data['shift'] as Map<String, dynamic>);
          _movements = _currentShift!.movements;
        }
        if (data['summary'] != null) {
          _currentSummary = CashSummaryModel.fromJson(data['summary'] as Map<String, dynamic>);
        }
      } else {
        _setError(res.message ?? 'Gagal memuat ringkasan kas');
      }
    } catch (e) {
      _setError('Error memuat ringkasan kas: $e');
    } finally {
      _setLoading(false);
    }
  }

  /// Opens a new shift for the outlet with opening cash float.
  Future<bool> openShift(
    String outletId,
    int openingCashMinor, {
    String? notes,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final res = await apiClient.post(
        '/outlets/$outletId/shifts/open',
        idempotencyKey: idempotencyKey,
        body: {
          'opening_cash_minor': openingCashMinor,
          if (notes != null && notes.isNotEmpty) 'notes': notes,
        },
      );

      if (res.isSuccess && res.data is Map<String, dynamic>) {
        _currentShift = ShiftModel.fromJson(res.data as Map<String, dynamic>);
        await refreshSummary(_currentShift!.id);
        return true;
      } else {
        _setError(res.message ?? 'Gagal membuka shift');
        return false;
      }
    } catch (e) {
      _setError('Error membuka shift: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Records cash in to the drawer.
  Future<bool> cashIn(String shiftId, int amountMinor, String reason) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final res = await apiClient.post(
        '/shifts/$shiftId/cash-in',
        idempotencyKey: idempotencyKey,
        body: {
          'amount_minor': amountMinor,
          'reason': reason,
        },
      );

      if (res.isSuccess) {
        await refreshSummary(shiftId);
        return true;
      } else {
        _setError(res.message ?? 'Gagal mencatat cash in');
        return false;
      }
    } catch (e) {
      _setError('Error cash in: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Records cash out from the drawer.
  Future<bool> cashOut(String shiftId, int amountMinor, String reason) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final res = await apiClient.post(
        '/shifts/$shiftId/cash-out',
        idempotencyKey: idempotencyKey,
        body: {
          'amount_minor': amountMinor,
          'reason': reason,
        },
      );

      if (res.isSuccess) {
        await refreshSummary(shiftId);
        return true;
      } else {
        _setError(res.message ?? 'Gagal mencatat cash out');
        return false;
      }
    } catch (e) {
      _setError('Error cash out: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Records cash adjustment (requires cash.adjust permission).
  Future<bool> cashAdjust(
    String shiftId,
    int amountMinor,
    String direction,
    String reason,
  ) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final res = await apiClient.post(
        '/shifts/$shiftId/adjust',
        idempotencyKey: idempotencyKey,
        body: {
          'amount_minor': amountMinor,
          'direction': direction,
          'reason': reason,
        },
      );

      if (res.isSuccess) {
        await refreshSummary(shiftId);
        return true;
      } else {
        _setError(res.message ?? 'Gagal mencatat penyesuaian kas');
        return false;
      }
    } catch (e) {
      _setError('Error penyesuaian kas: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }

  /// Closes an active shift with physical cash count.
  Future<bool> closeShift(
    String shiftId,
    int actualClosingCashMinor, {
    String? notes,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final res = await apiClient.post(
        '/shifts/$shiftId/close',
        idempotencyKey: idempotencyKey,
        body: {
          'actual_closing_cash_minor': actualClosingCashMinor,
          if (notes != null && notes.isNotEmpty) 'notes': notes,
        },
      );

      if (res.isSuccess && res.data is Map<String, dynamic>) {
        final data = res.data as Map<String, dynamic>;
        if (data['shift'] != null) {
          _currentShift = ShiftModel.fromJson(data['shift'] as Map<String, dynamic>);
        }
        return true;
      } else {
        _setError(res.message ?? 'Gagal menutup shift');
        return false;
      }
    } catch (e) {
      _setError('Error menutup shift: $e');
      return false;
    } finally {
      _setLoading(false);
    }
  }
}
