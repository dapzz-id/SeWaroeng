import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/dashboard/dashboard_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('fetchDashboard parses full dashboard model correctly', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/dashboard' && request.method == 'GET') {
        expect(request.url.queryParameters['period'], equals('today'));
        return http.Response(
          jsonEncode({
            'data': {
              'period': {
                'name': 'today',
                'start_date': '2026-10-06T00:00:00+07:00',
                'end_date': '2026-10-06T23:59:59+07:00',
                'timezone': 'Asia/Jakarta',
              },
              'kpi': {
                'gross_sales_minor': 10000000,
                'refunds_minor': 0,
                'net_sales_minor': 10000000,
                'transaction_count': 50,
                'average_order_value_minor': 200000,
                'gross_profit_minor': 4000000,
                'cogs_minor': 6000000,
                'net_cashflow_minor': 3000000,
              },
              'top_products': [
                {
                  'product_name': 'Kopi Susu Gula Aren',
                  'units_sold': 35,
                  'revenue_minor': 700000,
                },
              ],
              'low_stock': [
                {
                  'item_id': 'item-1',
                  'item_name': 'Biji Kopi Robusta',
                  'sku': 'KOP-ROB-001',
                  'available': 3.0,
                  'reorder_level': 10.0,
                  'locationName': 'Gudang Utama',
                  'outlet_id': 'out-1',
                },
              ],
              'top_customers': [
                {
                  'customer_id': 'cust-1',
                  'customer_name': 'Budi Santoso',
                  'transaction_count': 5,
                  'total_spend_minor': 250000,
                },
              ],
              'payment_summary': {
                'succeeded': 48,
                'processing': 1,
                'pending': 1,
                'failed': 0,
                'refunded': 0,
                'partially_refunded': 0,
              },
              'outlet_summary': [
                {
                  'outlet_id': 'out-1',
                  'outlet_name': 'Outlet Sudirman',
                  'transaction_count': 50,
                  'gross_sales_minor': 10000000,
                  'aov_minor': 200000,
                },
              ],
              'alerts': {
                'unread_count': 2,
                'has_critical': false,
              },
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = DashboardService(apiClient: apiClient);

    final data = await service.fetchDashboard(period: 'today');

    expect(data, isNotNull);
    expect(data!.period.name, equals('today'));
    expect(data.kpi.netSalesMinor, equals(10000000));
    expect(data.kpi.transactionCount, equals(50));
    expect(data.kpi.averageOrderValueMinor, equals(200000));
    expect(data.kpi.grossProfitMinor, equals(4000000));
    expect(data.topProducts.first.productName, equals('Kopi Susu Gula Aren'));
    expect(data.lowStock.first.itemName, equals('Biji Kopi Robusta'));
    expect(data.topCustomers.first.customerName, equals('Budi Santoso'));
    expect(data.paymentSummary['succeeded'], equals(48));
    expect(data.alerts.unreadCount, equals(2));
    expect(service.dashboardData, isNotNull);
  });

  test('fetchDashboard handles error properly', () async {
    final mockClient = MockClient((request) async {
      return http.Response(
        jsonEncode({
          'message': 'Unauthorized',
        }),
        403,
      );
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = DashboardService(apiClient: apiClient);

    final data = await service.fetchDashboard(period: 'today');

    expect(data, isNull);
    expect(service.errorMessage, isNotNull);
  });
}
