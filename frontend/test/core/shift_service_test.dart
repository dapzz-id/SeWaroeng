import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/shift/shift_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  test('fetchCurrentShift parses active shift and authoritative cash summary', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/outlets/out-1/shifts/current' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'shift': {
                'id': 'shift-100',
                'tenant_id': 'tenant-1',
                'outlet_id': 'out-1',
                'opened_by_user_id': 'user-1',
                'status': 'open',
                'opened_at': '2026-10-05T08:00:00.000Z',
                'opening_cash_minor': 100000,
                'opened_by': {'name': 'Budi Kasir'},
                'movements': [
                  {
                    'id': 'mov-1',
                    'tenant_id': 'tenant-1',
                    'outlet_id': 'out-1',
                    'shift_id': 'shift-100',
                    'type': 'opening',
                    'direction': 'in',
                    'amount_minor': 100000,
                    'reason': 'Opening Cash Float',
                    'performed_by_user_id': 'user-1',
                    'created_at': '2026-10-05T08:00:00.000Z',
                  }
                ],
              },
              'summary': {
                'opening_cash': 100000,
                'cash_sales': 50000,
                'cash_in': 20000,
                'cash_out': 10000,
                'cash_refund': 0,
                'adjustments': 0,
                'expected_closing': 160000,
                'movement_count': 4,
              }
            }
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost/api/v1', httpClient: mockClient);
    final service = ShiftService(apiClient: apiClient);

    await service.fetchCurrentShift('out-1');

    expect(service.hasActiveShift, isTrue);
    expect(service.currentShift?.id, 'shift-100');
    expect(service.currentShift?.openingCashMinor, 100000);
    expect(service.currentSummary?.expectedClosing, 160000);
    expect(service.currentSummary?.cashSales, 50000);
  });

  test('openShift sends idempotency key and opening cash float, updates currentShift', () async {
    String? capturedIdempotencyKey;
    Map<String, dynamic>? capturedBody;

    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/outlets/out-1/shifts/open' && request.method == 'POST') {
        capturedIdempotencyKey = request.headers['Idempotency-Key'];
        capturedBody = jsonDecode(request.body) as Map<String, dynamic>;

        return http.Response(
          jsonEncode({
            'data': {
              'id': 'shift-new-1',
              'tenant_id': 'tenant-1',
              'outlet_id': 'out-1',
              'opened_by_user_id': 'user-1',
              'status': 'open',
              'opened_at': '2026-10-05T09:00:00.000Z',
              'opening_cash_minor': 100000,
              'movements': [],
            }
          }),
          201,
        );
      }
      if (request.url.path == '/api/v1/shifts/shift-new-1/summary' && request.method == 'GET') {
        return http.Response(
          jsonEncode({
            'data': {
              'shift': {
                'id': 'shift-new-1',
                'tenant_id': 'tenant-1',
                'outlet_id': 'out-1',
                'opened_by_user_id': 'user-1',
                'status': 'open',
                'opened_at': '2026-10-05T09:00:00.000Z',
                'opening_cash_minor': 100000,
                'movements': [],
              },
              'summary': {
                'opening_cash': 100000,
                'cash_sales': 0,
                'cash_in': 0,
                'cash_out': 0,
                'cash_refund': 0,
                'adjustments': 0,
                'expected_closing': 100000,
                'movement_count': 1,
              }
            }
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost/api/v1', httpClient: mockClient);
    final service = ShiftService(apiClient: apiClient);

    final ok = await service.openShift('out-1', 100000, notes: 'Shift Pagi');

    expect(ok, isTrue);
    expect(capturedIdempotencyKey, isNotNull);
    expect(capturedBody?['opening_cash_minor'], 100000);
    expect(capturedBody?['notes'], 'Shift Pagi');
    expect(service.currentShift?.id, 'shift-new-1');
  });

  test('cashIn and cashOut send idempotency key and refresh cash summary', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/shifts/shift-1/cash-in' && request.method == 'POST') {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'mov-in-1',
              'shift_id': 'shift-1',
              'type': 'cash_in',
              'direction': 'in',
              'amount_minor': 50000,
            }
          }),
          201,
        );
      }
      if (request.url.path == '/api/v1/shifts/shift-1/cash-out' && request.method == 'POST') {
        return http.Response(
          jsonEncode({
            'data': {
              'id': 'mov-out-1',
              'shift_id': 'shift-1',
              'type': 'cash_out',
              'direction': 'out',
              'amount_minor': 30000,
            }
          }),
          201,
        );
      }
      if (request.url.path == '/api/v1/shifts/shift-1/summary') {
        return http.Response(
          jsonEncode({
            'data': {
              'shift': {
                'id': 'shift-1',
                'tenant_id': 'tenant-1',
                'outlet_id': 'out-1',
                'opened_by_user_id': 'user-1',
                'status': 'open',
                'opened_at': '2026-10-05T08:00:00.000Z',
                'opening_cash_minor': 100000,
                'movements': [],
              },
              'summary': {
                'opening_cash': 100000,
                'cash_sales': 0,
                'cash_in': 50000,
                'cash_out': 30000,
                'cash_refund': 0,
                'adjustments': 0,
                'expected_closing': 120000,
                'movement_count': 3,
              }
            }
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost/api/v1', httpClient: mockClient);
    final service = ShiftService(apiClient: apiClient);

    final okIn = await service.cashIn('shift-1', 50000, 'Tambah modal receh');
    expect(okIn, isTrue);

    final okOut = await service.cashOut('shift-1', 30000, 'Beli kantong plastik');
    expect(okOut, isTrue);

    expect(service.currentSummary?.expectedClosing, 120000);
  });

  test('closeShift sends actual closing count and updates status to closed', () async {
    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/shifts/shift-1/close' && request.method == 'POST') {
        final body = jsonDecode(request.body) as Map<String, dynamic>;
        expect(body['actual_closing_cash_minor'], 122000);
        expect(body['notes'], 'Lebih 2000');

        return http.Response(
          jsonEncode({
            'data': {
              'shift': {
                'id': 'shift-1',
                'tenant_id': 'tenant-1',
                'outlet_id': 'out-1',
                'opened_by_user_id': 'user-1',
                'closed_by_user_id': 'user-1',
                'status': 'closed',
                'opened_at': '2026-10-05T08:00:00.000Z',
                'closed_at': '2026-10-05T17:00:00.000Z',
                'opening_cash_minor': 100000,
                'closing_cash_minor': 122000,
                'expected_cash_minor': 120000,
                'variance_minor': 2000,
                'notes': 'Lebih 2000',
              }
            }
          }),
          200,
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost/api/v1', httpClient: mockClient);
    final service = ShiftService(apiClient: apiClient);

    final ok = await service.closeShift('shift-1', 122000, notes: 'Lebih 2000');
    expect(ok, isTrue);
    expect(service.currentShift?.isClosed, isTrue);
    expect(service.currentShift?.varianceMinor, 2000);
  });
}
