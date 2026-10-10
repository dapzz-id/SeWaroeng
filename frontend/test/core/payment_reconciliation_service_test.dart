import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/payment/payment_reconciliation_models.dart';
import 'package:frontend/core/payment/payment_reconciliation_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('PaymentReconciliationModel Tests', () {
    test('parses json correctly and computes helper properties', () {
      final json = {
        'id': 'recon-001',
        'tenant_id': 'tenant-001',
        'outlet_id': 'outlet-001',
        'payment_id': 'pay-001',
        'provider': 'midtrans',
        'provider_reference': 'mid-ref-123',
        'internal_reference': 'pay-ref-456',
        'expected_amount_minor': 100000,
        'provider_amount_minor': 100000,
        'fee_amount_minor': 2000,
        'net_amount_minor': 98000,
        'currency': 'IDR',
        'payment_status': 'settled',
        'settlement_status': 'settled',
        'status': 'matched',
        'reconciled_at': '2026-10-09T10:00:00Z',
        'settled_at': '2026-10-09T10:00:00Z',
        'mismatch_reason': null,
        'resolution_notes': null,
        'resolved_by': null,
        'resolved_at': null,
        'evidence_metadata': {'channel': 'qris'},
      };

      final model = PaymentReconciliationModel.fromJson(json);

      expect(model.id, equals('recon-001'));
      expect(model.provider, equals('midtrans'));
      expect(model.providerReference, equals('mid-ref-123'));
      expect(model.isMatched, isTrue);
      expect(model.isMismatched, isFalse);
      expect(model.hasDiscrepancy, isFalse);
      expect(model.discrepancyMinor, equals(0));
    });

    test('correctly identifies discrepancy and mismatch', () {
      final json = {
        'id': 'recon-002',
        'tenant_id': 'tenant-001',
        'outlet_id': 'outlet-001',
        'payment_id': 'pay-002',
        'provider': 'duitku',
        'provider_reference': 'duit-ref-999',
        'internal_reference': 'pay-ref-999',
        'expected_amount_minor': 100000,
        'provider_amount_minor': 90000,
        'fee_amount_minor': 0,
        'net_amount_minor': 90000,
        'currency': 'IDR',
        'payment_status': 'settled',
        'settlement_status': 'settled',
        'status': 'mismatched',
        'reconciled_at': '2026-10-09T10:00:00Z',
        'settled_at': null,
        'mismatch_reason': 'AMOUNT_MISMATCH',
        'resolution_notes': null,
        'resolved_by': null,
        'resolved_at': null,
        'evidence_metadata': null,
      };

      final model = PaymentReconciliationModel.fromJson(json);

      expect(model.isMismatched, isTrue);
      expect(model.isMatched, isFalse);
      expect(model.hasDiscrepancy, isTrue);
      expect(model.discrepancyMinor, equals(10000));
      expect(model.mismatchReason, equals('AMOUNT_MISMATCH'));
    });
  });

  group('PaymentReconciliationService Tests', () {
    test('fetchReconciliations retrieves and parses items list', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/payment-reconciliation' &&
            request.method == 'GET') {
          expect(request.url.queryParameters['outlet_id'], equals('out-1'));
          expect(request.url.queryParameters['status'], equals('matched'));
          return http.Response(
            jsonEncode({
              'status': 'success',
              'data': {
                'data': [
                  {
                    'id': 'recon-1',
                    'tenant_id': 'ten-1',
                    'outlet_id': 'out-1',
                    'payment_id': 'pay-1',
                    'provider': 'midtrans',
                    'provider_reference': 'trx-123',
                    'internal_reference': 'int-123',
                    'expected_amount_minor': 50000,
                    'provider_amount_minor': 50000,
                    'fee_amount_minor': 1000,
                    'net_amount_minor': 49000,
                    'currency': 'IDR',
                    'payment_status': 'settled',
                    'settlement_status': 'settled',
                    'status': 'matched',
                    'reconciled_at': '2026-10-09T12:00:00Z',
                    'settled_at': '2026-10-09T12:00:00Z',
                    'mismatch_reason': null,
                    'resolution_notes': null,
                    'resolved_by': null,
                    'resolved_at': null,
                    'evidence_metadata': {},
                  }
                ],
                'current_page': 1,
                'per_page': 20,
                'total': 1,
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
      final service = PaymentReconciliationService(apiClient: apiClient);

      final items = await service.fetchReconciliations(
        outletId: 'out-1',
        status: 'matched',
      );

      expect(items.length, equals(1));
      expect(items.first.id, equals('recon-1'));
      expect(items.first.providerReference, equals('trx-123'));
      expect(service.items.length, equals(1));
      expect(service.isLoading, isFalse);
      expect(service.errorMessage, isNull);
    });

    test('fetchSummary retrieves aggregate summary metrics', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/payment-reconciliation/summary' &&
            request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'status': 'success',
              'data': {
                'total_reconciled': 15,
                'matched_count': 12,
                'mismatched_count': 2,
                'unmatched_count': 1,
                'needs_review_count': 0,
                'resolved_count': 0,
                'total_expected_minor': 1500000,
                'total_settled_minor': 1400000,
                'total_discrepancy_minor': 100000,
                'total_fee_minor': 25000,
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
      final service = PaymentReconciliationService(apiClient: apiClient);

      final summary = await service.fetchSummary();

      expect(summary, isNotNull);
      expect(summary!.totalReconciled, equals(15));
      expect(summary.matchedCount, equals(12));
      expect(summary.mismatchedCount, equals(2));
      expect(summary.totalDiscrepancyMinor, equals(100000));
      expect(service.summary.totalReconciled, equals(15));
    });

    test('resolveDiscrepancy updates record and reloads summary', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/payment-reconciliation/recon-discrepant/resolve' &&
            request.method == 'POST') {
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          expect(body['resolution_notes'], equals('Verified with provider receipt.'));
          return http.Response(
            jsonEncode({
              'status': 'success',
              'data': {
                'id': 'recon-discrepant',
                'tenant_id': 'ten-1',
                'outlet_id': 'out-1',
                'payment_id': 'pay-1',
                'provider': 'midtrans',
                'provider_reference': 'trx-disc',
                'internal_reference': 'int-disc',
                'expected_amount_minor': 50000,
                'provider_amount_minor': 45000,
                'fee_amount_minor': 1000,
                'net_amount_minor': 44000,
                'currency': 'IDR',
                'payment_status': 'settled',
                'settlement_status': 'settled',
                'status': 'resolved',
                'reconciled_at': '2026-10-09T12:00:00Z',
                'settled_at': '2026-10-09T12:00:00Z',
                'mismatch_reason': 'AMOUNT_MISMATCH',
                'resolution_notes': 'Verified with provider receipt.',
                'resolved_by': 'user-123',
                'resolved_at': '2026-10-09T13:00:00Z',
                'evidence_metadata': {},
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        if (request.url.path == '/api/v1/payment-reconciliation/summary') {
          return http.Response(
            jsonEncode({
              'status': 'success',
              'data': {
                'total_reconciled': 1,
                'matched_count': 0,
                'mismatched_count': 0,
                'unmatched_count': 0,
                'needs_review_count': 0,
                'resolved_count': 1,
                'total_expected_minor': 50000,
                'total_settled_minor': 45000,
                'total_discrepancy_minor': 0,
                'total_fee_minor': 1000,
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
      final service = PaymentReconciliationService(apiClient: apiClient);

      final success = await service.resolveDiscrepancy(
        'recon-discrepant',
        'Verified with provider receipt.',
      );

      expect(success, isTrue);
      expect(service.summary.resolvedCount, equals(1));
    });

    test('importBatch posts items and reloads data', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/payment-reconciliation/import' &&
            request.method == 'POST') {
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          expect(body['items'], isA<List>());
          return http.Response(
            jsonEncode({
              'status': 'success',
              'data': {
                'total_processed': 2,
                'matched': 2,
                'mismatched': 0,
                'unmatched': 0,
                'errors': [],
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        if (request.url.path == '/api/v1/payment-reconciliation') {
          return http.Response(
            jsonEncode({'status': 'success', 'data': {'data': []}}),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        if (request.url.path == '/api/v1/payment-reconciliation/summary') {
          return http.Response(
            jsonEncode({
              'status': 'success',
              'data': {
                'total_reconciled': 2,
                'matched_count': 2,
                'mismatched_count': 0,
                'unmatched_count': 0,
                'needs_review_count': 0,
                'resolved_count': 0,
                'total_expected_minor': 20000,
                'total_settled_minor': 20000,
                'total_discrepancy_minor': 0,
                'total_fee_minor': 0,
              },
            }),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
      final service = PaymentReconciliationService(apiClient: apiClient);

      final result = await service.importBatch([
        {'provider': 'midtrans', 'transaction_reference': 'ref-1', 'amount': 10000},
        {'provider': 'midtrans', 'transaction_reference': 'ref-2', 'amount': 10000},
      ]);

      expect(result, isNotNull);
      expect(result!['total_processed'], equals(2));
      expect(result['matched'], equals(2));
    });
  });
}
