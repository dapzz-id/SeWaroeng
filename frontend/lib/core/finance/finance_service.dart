import 'package:flutter/foundation.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/finance/finance_models.dart';
import 'package:uuid/uuid.dart';

class FinanceService extends ChangeNotifier {
  final ApiClient apiClient;
  static const Uuid _uuid = Uuid();

  List<ExpenseModel> _expenses = [];
  ProfitLossReportModel? _profitLoss;
  CashFlowReportModel? _cashFlow;
  BalanceSheetReportModel? _balanceSheet;
  TrialBalanceReportModel? _trialBalance;
  AccountLedgerReportModel? _accountLedger;
  ClearingReportModel? _clearingSummary;
  final List<JournalEntryModel> _journalEntries = [];
  bool _isLoading = false;
  String? _errorMessage;

  List<ExpenseModel> get expenses => _expenses;
  ProfitLossReportModel? get profitLoss => _profitLoss;
  CashFlowReportModel? get cashFlow => _cashFlow;
  BalanceSheetReportModel? get balanceSheet => _balanceSheet;
  TrialBalanceReportModel? get trialBalance => _trialBalance;
  AccountLedgerReportModel? get accountLedger => _accountLedger;
  ClearingReportModel? get clearingSummary => _clearingSummary;
  List<JournalEntryModel> get journalEntries => _journalEntries;
  bool get isLoading => _isLoading;
  String? get errorMessage => _errorMessage;

  FinanceService({required this.apiClient});

  void _setLoading(bool value) {
    _isLoading = value;
    notifyListeners();
  }

  void _setError(String? message) {
    _errorMessage = message;
    notifyListeners();
  }

  /// Fetch expenses with optional filters
  Future<List<ExpenseModel>> fetchExpenses({
    String? outletId,
    String? status,
    String? category,
    String? startDate,
    String? endDate,
    int page = 1,
    int perPage = 20,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final params = <String>[];
      if (outletId != null && outletId.isNotEmpty) params.add('outlet_id=$outletId');
      if (status != null && status.isNotEmpty) params.add('status=$status');
      if (category != null && category.isNotEmpty) params.add('category=$category');
      if (startDate != null && startDate.isNotEmpty) params.add('start_date=$startDate');
      if (endDate != null && endDate.isNotEmpty) params.add('end_date=$endDate');
      params.add('page=$page');
      params.add('per_page=$perPage');

      final queryStr = params.isNotEmpty ? '?${params.join('&')}' : '';
      final response = await apiClient.get<dynamic>('/api/v1/finance/expenses$queryStr');

      if (response.isSuccess && response.data != null) {
        final data = response.data;
        List<dynamic> list = [];
        if (data is List) {
          list = data;
        } else if (data is Map && data['data'] is List) {
          list = data['data'] as List;
        }

        _expenses = list.map((e) => ExpenseModel.fromJson(e as Map<String, dynamic>)).toList();
        _setLoading(false);
        return _expenses;
      } else {
        _setError(response.message ?? 'Gagal memuat daftar pengeluaran.');
        _setLoading(false);
        return [];
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      _setLoading(false);
      return [];
    }
  }

  /// Create a new operational expense
  Future<ExpenseModel?> createExpense({
    String? outletId,
    String? accountId,
    required String category,
    required int amountMinor,
    required String expenseDate,
    required String paymentMethod,
    String? description,
    String? referenceNumber,
    String status = 'posted',
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final payload = <String, dynamic>{
        'category': category,
        'amount_minor': amountMinor,
        'expense_date': expenseDate,
        'payment_method': paymentMethod,
        'status': status,
      };
      if (outletId != null) payload['outlet_id'] = outletId;
      if (accountId != null) payload['account_id'] = accountId;
      if (description != null && description.isNotEmpty) payload['description'] = description;
      if (referenceNumber != null && referenceNumber.isNotEmpty) {
        payload['reference_number'] = referenceNumber;
      }

      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/finance/expenses',
        body: payload,
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess && response.data != null) {
        final Map<String, dynamic> itemData = (response.data is Map && response.data['data'] != null)
            ? response.data['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;

        final created = ExpenseModel.fromJson(itemData);
        _expenses.insert(0, created);
        _setLoading(false);
        return created;
      } else {
        _setError(response.message ?? 'Gagal mencatat pengeluaran.');
        _setLoading(false);
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      _setLoading(false);
      return null;
    }
  }

  /// Reverse a posted expense
  Future<bool> reverseExpense(String id, String reason) async {
    _setLoading(true);
    _setError(null);

    try {
      final idempotencyKey = _uuid.v7();
      final response = await apiClient.post<dynamic>(
        '/api/v1/finance/expenses/$id/reverse',
        body: {'reason': reason},
        idempotencyKey: idempotencyKey,
      );

      if (response.isSuccess) {
        final index = _expenses.indexWhere((e) => e.id == id);
        if (index != -1 && response.data != null) {
          final Map<String, dynamic> itemData = (response.data is Map && response.data['data'] != null)
              ? response.data['data'] as Map<String, dynamic>
              : response.data as Map<String, dynamic>;
          _expenses[index] = ExpenseModel.fromJson(itemData);
        }
        _setLoading(false);
        return true;
      } else {
        _setError(response.message ?? 'Gagal membatalkan pengeluaran.');
        _setLoading(false);
        return false;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      _setLoading(false);
      return false;
    }
  }

  /// Fetch Profit & Loss report
  Future<ProfitLossReportModel?> fetchProfitLoss({
    String? outletId,
    String period = 'this_month',
    String? startDate,
    String? endDate,
    bool compare = false,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final params = <String>['period=$period'];
      if (outletId != null && outletId.isNotEmpty) params.add('outlet_id=$outletId');
      if (startDate != null && startDate.isNotEmpty) params.add('start_date=$startDate');
      if (endDate != null && endDate.isNotEmpty) params.add('end_date=$endDate');
      if (compare) params.add('compare=1');

      final queryStr = '?${params.join('&')}';
      final response = await apiClient.get<dynamic>('/api/v1/finance/profit-loss$queryStr');

      if (response.isSuccess && response.data != null) {
        final Map<String, dynamic> data = (response.data is Map && response.data['data'] != null)
            ? response.data['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;

        _profitLoss = ProfitLossReportModel.fromJson(data);
        _setLoading(false);
        return _profitLoss;
      } else {
        _setError(response.message ?? 'Gagal memuat laporan Laba Rugi.');
        _setLoading(false);
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      _setLoading(false);
      return null;
    }
  }

  /// Fetch Cash Flow report
  Future<CashFlowReportModel?> fetchCashFlow({
    String? outletId,
    String period = 'this_month',
    String? startDate,
    String? endDate,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final params = <String>['period=$period'];
      if (outletId != null && outletId.isNotEmpty) params.add('outlet_id=$outletId');
      if (startDate != null && startDate.isNotEmpty) params.add('start_date=$startDate');
      if (endDate != null && endDate.isNotEmpty) params.add('end_date=$endDate');

      final queryStr = '?${params.join('&')}';
      final response = await apiClient.get<dynamic>('/api/v1/finance/cash-flow$queryStr');

      if (response.isSuccess && response.data != null) {
        final Map<String, dynamic> data = (response.data is Map && response.data['data'] != null)
            ? response.data['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;

        _cashFlow = CashFlowReportModel.fromJson(data);
        _setLoading(false);
        return _cashFlow;
      } else {
        _setError(response.message ?? 'Gagal memuat laporan Arus Kas.');
        _setLoading(false);
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      _setLoading(false);
      return null;
    }
  }

  /// Fetch Balance Sheet report
  Future<BalanceSheetReportModel?> fetchBalanceSheet({
    String? outletId,
    String? asOfDate,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final params = <String>[];
      if (outletId != null && outletId.isNotEmpty) params.add('outlet_id=$outletId');
      if (asOfDate != null && asOfDate.isNotEmpty) params.add('as_of_date=$asOfDate');

      final queryStr = params.isNotEmpty ? '?${params.join('&')}' : '';
      final response = await apiClient.get<dynamic>('/api/v1/finance/balance-sheet$queryStr');

      if (response.isSuccess && response.data != null) {
        final Map<String, dynamic> data = (response.data is Map && response.data['data'] != null)
            ? response.data['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;

        _balanceSheet = BalanceSheetReportModel.fromJson(data);
        _setLoading(false);
        return _balanceSheet;
      } else {
        _setError(response.message ?? 'Gagal memuat Neraca Keuangan.');
        _setLoading(false);
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      _setLoading(false);
      return null;
    }
  }

  /// Fetch Trial Balance report
  Future<TrialBalanceReportModel?> fetchTrialBalance({
    String? outletId,
    String? asOfDate,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final params = <String>[];
      if (outletId != null && outletId.isNotEmpty) params.add('outlet_id=$outletId');
      if (asOfDate != null && asOfDate.isNotEmpty) params.add('as_of_date=$asOfDate');

      final queryStr = params.isNotEmpty ? '?${params.join('&')}' : '';
      final response = await apiClient.get<dynamic>('/api/v1/finance/trial-balance$queryStr');

      if (response.isSuccess && response.data != null) {
        final Map<String, dynamic> data = (response.data is Map && response.data['data'] != null)
            ? response.data['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;

        _trialBalance = TrialBalanceReportModel.fromJson(data);
        _setLoading(false);
        return _trialBalance;
      } else {
        _setError(response.message ?? 'Gagal memuat Neraca Saldo.');
        _setLoading(false);
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      _setLoading(false);
      return null;
    }
  }

  /// Fetch Account Ledger (Buku Besar)
  Future<AccountLedgerReportModel?> fetchAccountLedger({
    required String accountCode,
    String? outletId,
    String period = 'this_month',
    String? startDate,
    String? endDate,
    int page = 1,
    int perPage = 50,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final params = <String>['account_code=$accountCode', 'period=$period', 'page=$page', 'per_page=$perPage'];
      if (outletId != null && outletId.isNotEmpty) params.add('outlet_id=$outletId');
      if (startDate != null && startDate.isNotEmpty) params.add('start_date=$startDate');
      if (endDate != null && endDate.isNotEmpty) params.add('end_date=$endDate');

      final queryStr = '?${params.join('&')}';
      final response = await apiClient.get<dynamic>('/api/v1/finance/account-ledger$queryStr');

      if (response.isSuccess && response.data != null) {
        final Map<String, dynamic> data = (response.data is Map && response.data['data'] != null)
            ? response.data['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;

        _accountLedger = AccountLedgerReportModel.fromJson(data);
        _setLoading(false);
        return _accountLedger;
      } else {
        _setError(response.message ?? 'Gagal memuat Buku Besar Akun.');
        _setLoading(false);
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      _setLoading(false);
      return null;
    }
  }

  /// Fetch Payment Clearing Summary
  Future<ClearingReportModel?> fetchClearingSummary({
    String? outletId,
    String period = 'this_month',
    String? startDate,
    String? endDate,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final params = <String>['period=$period'];
      if (outletId != null && outletId.isNotEmpty) params.add('outlet_id=$outletId');
      if (startDate != null && startDate.isNotEmpty) params.add('start_date=$startDate');
      if (endDate != null && endDate.isNotEmpty) params.add('end_date=$endDate');

      final queryStr = '?${params.join('&')}';
      final response = await apiClient.get<dynamic>('/api/v1/finance/clearing-summary$queryStr');

      if (response.isSuccess && response.data != null) {
        final Map<String, dynamic> data = (response.data is Map && response.data['data'] != null)
            ? response.data['data'] as Map<String, dynamic>
            : response.data as Map<String, dynamic>;

        _clearingSummary = ClearingReportModel.fromJson(data);
        _setLoading(false);
        return _clearingSummary;
      } else {
        _setError(response.message ?? 'Gagal memuat Ringkasan Kliring Pembayaran.');
        _setLoading(false);
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan: $e');
      _setLoading(false);
      return null;
    }
  }

  /// Export Financial Report in CSV, XLSX, or PDF format.
  /// Returns raw bytes of the file, or null on failure.
  Future<List<int>?> exportReport({
    required String reportType,
    required String format,
    String? outletId,
    String period = 'this_month',
    String? startDate,
    String? endDate,
    String? accountCode,
    String? asOfDate,
    bool compare = false,
  }) async {
    _setLoading(true);
    _setError(null);

    try {
      final params = <String>['format=$format', 'period=$period'];
      if (outletId != null && outletId.isNotEmpty) params.add('outlet_id=$outletId');
      if (startDate != null && startDate.isNotEmpty) params.add('start_date=$startDate');
      if (endDate != null && endDate.isNotEmpty) params.add('end_date=$endDate');
      if (accountCode != null && accountCode.isNotEmpty) params.add('account_code=$accountCode');
      if (asOfDate != null && asOfDate.isNotEmpty) params.add('as_of_date=$asOfDate');
      if (compare) params.add('compare=1');

      final queryStr = '?${params.join('&')}';
      final response = await apiClient.getRaw('/api/v1/finance/$reportType/export$queryStr');

      _setLoading(false);
      if (response.statusCode >= 200 && response.statusCode < 300) {
        return response.bodyBytes;
      } else {
        _setError('Gagal mengunduh laporan (Status ${response.statusCode})');
        return null;
      }
    } catch (e) {
      _setError('Terjadi kesalahan export: $e');
      _setLoading(false);
      return null;
    }
  }
}
