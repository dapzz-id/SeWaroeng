import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/finance/finance_service.dart';
import 'package:frontend/presentation/finance/expense_form_dialog.dart';
import 'package:frontend/presentation/finance/finance_overview_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late MockClient mockClient;
  late ApiClient apiClient;
  late FinanceService financeService;

  setUp(() {
    mockClient = MockClient((request) async {
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
                'categories': {'utilities': 2500000},
              },
              'period': {'start_date': '2026-10-01', 'end_date': '2026-10-31'},
            },
          }),
          200,
        );
      }
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
      if (request.url.path == '/api/v1/finance/balance-sheet') {
        return http.Response(
          jsonEncode({
            'data': {
              'as_of_date': '2026-10-31',
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
      if (request.url.path == '/api/v1/finance/expenses') {
        if (request.method == 'GET') {
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
                  'description': 'Token Listrik',
                },
              ],
            }),
            200,
          );
        } else if (request.method == 'POST') {
          return http.Response(
            jsonEncode({
              'data': {
                'id': 'exp-new',
                'tenant_id': 'tenant-1',
                'category': 'utilities',
                'amount_minor': 100000,
                'expense_date': '2026-10-06',
                'payment_method': 'cash',
                'status': 'posted',
              },
            }),
            201,
          );
        }
      }
      return http.Response('Not Found', 404);
    });

    apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    financeService = FinanceService(apiClient: apiClient);
  });

  testWidgets('FinanceOverviewScreen renders tabs and metrics for owner', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: FinanceOverviewScreen(
          financeService: financeService,
          userRole: 'owner',
        ),
      ),
    );

    // Initial load
    await tester.pumpAndSettle();

    // Verify Tab headers
    expect(find.text('Laba Rugi (P&L)'), findsOneWidget);
    expect(find.text('Arus Kas'), findsOneWidget);
    expect(find.text('Beban Operasional'), findsOneWidget);

    // Verify P&L metrics displayed
    expect(find.text('Pendapatan Bersih (Net Revenue)'), findsOneWidget);
    expect(find.text('Harga Pokok Penjualan (HPP)'), findsOneWidget);
    expect(find.text('Laba Kotor (Gross Profit)'), findsOneWidget);
    expect(find.text('Laba Bersih (Net Profit)'), findsOneWidget);

    // Switch to Cash Flow tab
    await tester.tap(find.text('Arus Kas'));
    await tester.pumpAndSettle();

    expect(find.text('Uang Masuk (Cash In)'), findsOneWidget);
    expect(find.text('Uang Keluar (Cash Out)'), findsOneWidget);
    expect(find.text('Arus Kas Bersih (Net Cashflow)'), findsOneWidget);

    // Switch to Expenses tab
    await tester.tap(find.text('Beban Operasional'));
    await tester.pumpAndSettle();

    expect(find.text('Utilitas & Listrik'), findsOneWidget);
    expect(find.text('Terposting'), findsNWidgets(2));
    expect(find.byKey(const Key('add_expense_fab')), findsOneWidget);

    // Switch to Balance Sheet (Neraca) tab
    await tester.tap(find.text('Neraca Keuangan'));
    await tester.pumpAndSettle();

    expect(find.text('ASET (AKTIVA)'), findsOneWidget);
    expect(find.text('Neraca Seimbang (Aset = Kewajiban + Ekuitas)'), findsOneWidget);
    expect(find.text('Kas Tunai (1110)'), findsOneWidget);
  });

  testWidgets('FinanceOverviewScreen renders export button and opens popup menu', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: FinanceOverviewScreen(
          financeService: financeService,
          userRole: 'owner',
        ),
      ),
    );

    await tester.pumpAndSettle();

    expect(find.byKey(const Key('export_report_button')), findsOneWidget);

    await tester.tap(find.byKey(const Key('export_report_button')));
    await tester.pumpAndSettle();

    expect(find.text('PDF'), findsOneWidget);
    expect(find.text('Excel (XLSX)'), findsOneWidget);
    expect(find.text('CSV'), findsOneWidget);
  });

  testWidgets('FinanceOverviewScreen displays restricted view for cashier', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: FinanceOverviewScreen(
          financeService: financeService,
          userRole: 'cashier',
        ),
      ),
    );

    await tester.pumpAndSettle();

    expect(find.text('Akses Terbatas'), findsOneWidget);
    expect(find.text('Laba Rugi (P&L)'), findsNothing);
  });

  testWidgets('ExpenseFormDialog validates positive amount before submission', (tester) async {
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ExpenseFormDialog(financeService: financeService),
        ),
      ),
    );

    await tester.pumpAndSettle();

    // Submit with empty amount
    await tester.tap(find.byKey(const Key('expense_submit_button')));
    await tester.pumpAndSettle();

    expect(find.text('Nominal wajib diisi'), findsOneWidget);

    // Enter positive amount and submit
    await tester.enterText(find.byKey(const Key('expense_amount_input')), '100000');
    await tester.tap(find.byKey(const Key('expense_submit_button')));
    await tester.pumpAndSettle();

    expect(find.text('Nominal wajib diisi'), findsNothing);
  });
}
