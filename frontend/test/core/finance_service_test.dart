import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/finance/finance_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('fetchExpenses parses list and categories properly', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/finance/expenses' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'exp-1',
                'tenant_id': 'tenant-1',
                'category': 'utilities',
                'amount_minor': 250000,
                'expense_date': '2026-10-06',
                'payment_method': 'cash',
                'status': 'posted',
                'description': 'Listrik warung',
              },
              {
                'id': 'exp-2',
                'tenant_id': 'tenant-1',
                'category': 'rent',
                'amount_minor': 1000000,
                'expense_date': '2026-10-01',
                'payment_method': 'bank',
                'status': 'posted',
              },
            ],
            'meta': {'current_page': 1, 'per_page': 20, 'total': 2},
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = FinanceService(apiClient: apiClient);

    final expenses = await service.fetchExpenses();

    expect(expenses.length, equals(2));
    expect(expenses.first.category, equals('utilities'));
    expect(expenses.first.amountMinor, equals(250000));
    expect(expenses.first.paymentMethod, equals('cash'));
    expect(expenses.first.isPosted, isTrue);
    expect(expenses.last.category, equals('rent'));
  });

  test('createExpense sends idempotency key and parses result', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/finance/expenses' && request.method == 'POST') {
        expect(request.headers['Idempotency-Key'], isNotEmpty);
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['category'], equals('supplies'));
        expect(body['amount_minor'], equals(50000));
        expect(body['payment_method'], equals('cash'));

        return http.Response(
          jsonEncode({
            'data': {
              'id': 'exp-created-1',
              'tenant_id': 'tenant-1',
              'category': 'supplies',
              'amount_minor': 50000,
              'expense_date': '2026-10-06',
              'payment_method': 'cash',
              'status': 'posted',
            },
          }),
          201,
        );
      }
      return http.Response('Bad Request', 400);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = FinanceService(apiClient: apiClient);

    final created = await service.createExpense(
      category: 'supplies',
      amountMinor: 50000,
      expenseDate: '2026-10-06',
      paymentMethod: 'cash',
    );

    expect(created, isNotNull);
    expect(created!.id, equals('exp-created-1'));
    expect(created.amountMinor, equals(50000));
  });

  test('reverseExpense posts reversal and updates state', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/finance/expenses/exp-1/reverse' && request.method == 'POST') {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['reason'], equals('Salah input'));

        return http.Response(
          jsonEncode({
            'data': {
              'id': 'exp-1',
              'tenant_id': 'tenant-1',
              'category': 'utilities',
              'amount_minor': 250000,
              'expense_date': '2026-10-06',
              'payment_method': 'cash',
              'status': 'reversed',
            },
          }),
          200,
        );
      }
      return http.Response('Bad Request', 400);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = FinanceService(apiClient: apiClient);

    final ok = await service.reverseExpense('exp-1', 'Salah input');
    expect(ok, isTrue);
  });

  test('fetchProfitLoss parses P&L report properly', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/finance/profit-loss') {
        return http.Response(
          jsonEncode({
            'data': {
              'revenue_minor': 10000000,
              'cogs_minor': 4000000,
              'gross_profit_minor': 6000000,
              'expense_minor': 2500000,
              'net_profit_minor': 3500000,
              'breakdown': {
                'gross_revenue_minor': 10000000,
                'sales_return_minor': 0,
                'categories': {'utilities': 1500000, 'supplies': 1000000},
              },
              'period': {'start_date': '2026-10-01', 'end_date': '2026-10-31'},
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = FinanceService(apiClient: apiClient);

    final pnl = await service.fetchProfitLoss();

    expect(pnl, isNotNull);
    expect(pnl!.revenueMinor, equals(10000000));
    expect(pnl.cogsMinor, equals(4000000));
    expect(pnl.grossProfitMinor, equals(6000000));
    expect(pnl.expenseMinor, equals(2500000));
    expect(pnl.netProfitMinor, equals(3500000));
    expect(pnl.categories['utilities'], equals(1500000));
  });

  test('fetchCashFlow parses cash in and cash out report properly', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/finance/cash-flow') {
        return http.Response(
          jsonEncode({
            'data': {
              'cash_in_minor': 7000000,
              'cash_out_minor': 3000000,
              'net_cashflow_minor': 4000000,
              'breakdown': {
                'cash_sales_minor': 7000000,
                'cash_expenses_minor': 3000000,
                'cash_refunds_minor': 0,
              },
              'period': {'start_date': '2026-10-01', 'end_date': '2026-10-31'},
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = FinanceService(apiClient: apiClient);

    final cf = await service.fetchCashFlow();

    expect(cf, isNotNull);
    expect(cf!.cashInMinor, equals(7000000));
    expect(cf.cashOutMinor, equals(3000000));
    expect(cf.netCashflowMinor, equals(4000000));
    expect(cf.cashSalesMinor, equals(7000000));
    expect(cf.cashExpensesMinor, equals(3000000));
  });

  test('fetchBalanceSheet parses assets, liabilities, and equity with invariant', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/finance/balance-sheet') {
        return http.Response(
          jsonEncode({
            'data': {
              'as_of_date': '2026-10-07',
              'timezone': 'Asia/Jakarta',
              'assets': {
                'cash_minor': 7000000,
                'clearing_minor': 5500000,
                'inventory_minor': 1500000,
                'total_assets_minor': 14000000,
              },
              'liabilities': {
                'tax_payable_minor': 500000,
                'total_liabilities_minor': 500000,
              },
              'equity': {
                'owner_equity_minor': 10000000,
                'retained_earnings_minor': 3500000,
                'total_equity_minor': 13500000,
              },
              'total_liabilities_and_equity_minor': 14000000,
              'is_balanced': true,
              'discrepancy_minor': 0,
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = FinanceService(apiClient: apiClient);

    final bs = await service.fetchBalanceSheet();

    expect(bs, isNotNull);
    expect(bs!.isBalanced, isTrue);
    expect(bs.totalAssetsMinor, equals(14000000));
    expect(bs.totalLiabilitiesAndEquityMinor, equals(14000000));
    expect(bs.discrepancyMinor, equals(0));
    expect(bs.cashMinor, equals(7000000));
    expect(bs.clearingMinor, equals(5500000));
    expect(bs.inventoryMinor, equals(1500000));
  });

  test('fetchTrialBalance parses all accounts and debit credit balance', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/finance/trial-balance') {
        return http.Response(
          jsonEncode({
            'data': {
              'as_of_date': '2026-10-07',
              'timezone': 'Asia/Jakarta',
              'accounts': [
                {
                  'account_id': 'acc-1',
                  'account_code': '1110',
                  'account_name': 'Kas Toko',
                  'account_type': 'asset',
                  'total_debit_minor': 500000,
                  'total_credit_minor': 0,
                  'ending_debit_minor': 500000,
                  'ending_credit_minor': 0,
                },
                {
                  'account_id': 'acc-2',
                  'account_code': '4100',
                  'account_name': 'Pendapatan',
                  'account_type': 'revenue',
                  'total_debit_minor': 0,
                  'total_credit_minor': 500000,
                  'ending_debit_minor': 0,
                  'ending_credit_minor': 500000,
                },
              ],
              'total_debit_minor': 500000,
              'total_credit_minor': 500000,
              'is_balanced': true,
              'discrepancy_minor': 0,
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = FinanceService(apiClient: apiClient);

    final tb = await service.fetchTrialBalance();

    expect(tb, isNotNull);
    expect(tb!.isBalanced, isTrue);
    expect(tb.accounts.length, equals(2));
    expect(tb.totalDebitMinor, equals(500000));
    expect(tb.totalCreditMinor, equals(500000));
  });

  test('fetchAccountLedger parses running balance entries', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/finance/account-ledger') {
        expect(request.url.queryParameters['account_code'], equals('1110'));
        return http.Response(
          jsonEncode({
            'data': {
              'account': {
                'id': 'acc-1',
                'code': '1110',
                'name': 'Kas Toko',
                'type': 'asset',
                'normal_balance': 'debit',
              },
              'period': {'start_date': '2026-10-01', 'end_date': '2026-10-31'},
              'opening_balance_minor': 300000,
              'total_debit_minor': 200000,
              'total_credit_minor': 50000,
              'closing_balance_minor': 450000,
              'pagination': {'current_page': 1, 'per_page': 50, 'total': 1, 'last_page': 1},
              'lines': [
                {
                  'id': 'line-1',
                  'entry_id': 'je-1',
                  'entry_number': 'JE-001',
                  'entry_date': '2026-10-02',
                  'source_type': 'sale',
                  'description': 'Penjualan',
                  'debit_minor': 200000,
                  'credit_minor': 0,
                  'running_balance_minor': 500000,
                },
              ],
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = FinanceService(apiClient: apiClient);

    final ledger = await service.fetchAccountLedger(accountCode: '1110');

    expect(ledger, isNotNull);
    expect(ledger!.accountCode, equals('1110'));
    expect(ledger.openingBalanceMinor, equals(300000));
    expect(ledger.closingBalanceMinor, equals(450000));
    expect(ledger.lines.length, equals(1));
    expect(ledger.lines.first.runningBalanceMinor, equals(500000));
  });

  test('fetchClearingSummary parses clearing ledger and reconciliation overlay', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/finance/clearing-summary') {
        return http.Response(
          jsonEncode({
            'data': {
              'period': {'start_date': '2026-10-01', 'end_date': '2026-10-31'},
              'clearing_ledger': {
                'account_code': '1120',
                'debit_minor': 250000,
                'credit_minor': 0,
                'net_movement_minor': 250000,
              },
              'reconciliation_verification': {
                'matched_count': 1,
                'matched_amount_minor': 250000,
                'mismatched_count': 0,
                'mismatched_amount_minor': 0,
                'unresolved_count': 0,
                'unresolved_amount_minor': 0,
                'settled_count': 1,
                'settled_amount_minor': 248250,
                'pending_settlement_count': 0,
                'pending_settlement_amount_minor': 0,
              },
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = FinanceService(apiClient: apiClient);

    final clearing = await service.fetchClearingSummary();

    expect(clearing, isNotNull);
    expect(clearing!.accountCode, equals('1120'));
    expect(clearing.clearingDebitMinor, equals(250000));
    expect(clearing.matchedCount, equals(1));
    expect(clearing.settledAmountMinor, equals(248250));
  });

  test('exportReport requests export endpoint with format and returns bytes', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/finance/profit-loss/export') {
        expect(request.url.queryParameters['format'], equals('pdf'));
        expect(request.url.queryParameters['period'], equals('this_month'));
        return http.Response.bytes(
          utf8.encode('%PDF-1.4 Mock PDF Content'),
          200,
          headers: {'content-type': 'application/pdf'},
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = FinanceService(apiClient: apiClient);

    final bytes = await service.exportReport(reportType: 'profit-loss', format: 'pdf');

    expect(bytes, isNotNull);
    expect(utf8.decode(bytes!), contains('%PDF-1.4 Mock PDF Content'));
  });
}
