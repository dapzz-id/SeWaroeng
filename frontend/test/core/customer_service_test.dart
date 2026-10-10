import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/customer/customer_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('fetchCustomers parses list and segment properly', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/customers' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'id': 'cust-1',
                'tenant_id': 'tenant-1',
                'name': 'John Doe',
                'phone': '+6281234567890',
                'email': 'john@example.com',
                'segment': 'high_value',
                'is_active': true,
              },
              {
                'id': 'cust-2',
                'tenant_id': 'tenant-1',
                'name': 'Jane Smith',
                'segment': 'prospect',
                'is_active': true,
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
    final service = CustomerService(apiClient: apiClient);

    final customers = await service.fetchCustomers();

    expect(customers.length, equals(2));
    expect(customers.first.name, equals('John Doe'));
    expect(customers.first.phone, equals('+6281234567890'));
    expect(customers.first.segment, equals('high_value'));
    expect(customers.last.name, equals('Jane Smith'));
    expect(customers.last.segment, equals('prospect'));
  });

  test('createCustomer sends normalized payload and parses created customer', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/customers' && request.method == 'POST') {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['name'], equals('Ahmad Kasir'));
        expect(body['phone'], equals('08123456789'));

        return http.Response(
          jsonEncode({
            'data': {
              'id': 'cust-new',
              'tenant_id': 'tenant-1',
              'name': 'Ahmad Kasir',
              'phone': '+628123456789',
              'email': null,
              'segment': 'prospect',
              'is_active': true,
              'marketing_opt_in': false,
            },
            'message': 'Customer created successfully.',
          }),
          201,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = CustomerService(apiClient: apiClient);

    final created = await service.createCustomer(
      name: 'Ahmad Kasir',
      phone: '08123456789',
    );

    expect(created, isNotNull);
    expect(created!.id, equals('cust-new'));
    expect(created.name, equals('Ahmad Kasir'));
    expect(created.phone, equals('+628123456789'));
    expect(service.customers.length, equals(1));
  });

  test('getCustomerSummary parses authoritative spend and frequency metrics', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/customers/cust-1/summary' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'total_transactions': 5,
              'successful_transactions': 4,
              'total_spend_minor': 10000000,
              'average_order_value_minor': 2500000,
              'segment': 'returning',
            },
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = CustomerService(apiClient: apiClient);

    final summary = await service.getCustomerSummary('cust-1');

    expect(summary, isNotNull);
    expect(summary!.totalTransactions, equals(5));
    expect(summary.successfulTransactions, equals(4));
    expect(summary.totalSpendMinor, equals(10000000));
    expect(summary.averageOrderValueMinor, equals(2500000));
    expect(summary.segment, equals('returning'));
  });

  test('getCustomerHistory parses sales list associated with customer', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/customers/cust-1/history' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': [
              {
                'sale_id': 'sale-1',
                'reference_number': 'SAL-202610-001',
                'finalized_at': '2026-10-06T10:00:00Z',
                'status': 'finalized',
                'payment_status': 'paid',
                'payment_method': 'cash',
                'item_count': 2,
                'total_minor': 3600000,
                'paid_minor': 3600000,
                'outlet_name': 'Outlet Utama',
              },
            ],
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final service = CustomerService(apiClient: apiClient);

    final history = await service.getCustomerHistory('cust-1');

    expect(history.length, equals(1));
    expect(history.first.referenceNumber, equals('SAL-202610-001'));
    expect(history.first.paymentMethod, equals('cash'));
    expect(history.first.totalMinor, equals(3600000));
  });
}
