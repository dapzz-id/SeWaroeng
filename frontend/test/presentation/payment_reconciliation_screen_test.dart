import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/payment/payment_reconciliation_service.dart';
import 'package:frontend/presentation/finance/payment_reconciliation_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  testWidgets('renders payment reconciliation screen with summary and list', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/payment-reconciliation') {
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
                  'provider_reference': 'MID-TRX-1001',
                  'internal_reference': 'INT-1001',
                  'expected_amount_minor': 150000,
                  'provider_amount_minor': 150000,
                  'fee_amount_minor': 1500,
                  'net_amount_minor': 148500,
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
                },
                {
                  'id': 'recon-2',
                  'tenant_id': 'ten-1',
                  'outlet_id': 'out-1',
                  'payment_id': 'pay-2',
                  'provider': 'duitku',
                  'provider_reference': 'DUIT-TRX-2002',
                  'internal_reference': 'INT-2002',
                  'expected_amount_minor': 100000,
                  'provider_amount_minor': 80000,
                  'fee_amount_minor': 0,
                  'net_amount_minor': 80000,
                  'currency': 'IDR',
                  'payment_status': 'settled',
                  'settlement_status': 'settled',
                  'status': 'mismatched',
                  'reconciled_at': '2026-10-09T11:00:00Z',
                  'settled_at': null,
                  'mismatch_reason': 'AMOUNT_MISMATCH',
                  'resolution_notes': null,
                  'resolved_by': null,
                  'resolved_at': null,
                  'evidence_metadata': null,
                },
              ],
              'current_page': 1,
              'per_page': 20,
              'total': 2,
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
              'total_reconciled': 2,
              'matched_count': 1,
              'mismatched_count': 1,
              'unmatched_count': 0,
              'needs_review_count': 0,
              'resolved_count': 0,
              'total_expected_minor': 250000,
              'total_settled_minor': 230000,
              'total_discrepancy_minor': 20000,
              'total_fee_minor': 1500,
            },
          }),
          200,
          headers: {'content-type': 'application/json'},
        );
      }
      return http.Response('Not Found', 404);
    });

    final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
    final reconciliationService = PaymentReconciliationService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: PaymentReconciliationScreen(
          reconciliationService: reconciliationService,
          canResolve: true,
        ),
      ),
    );

    await tester.pumpAndSettle();

    // Verify app bar title
    expect(find.text('Rekonsiliasi Pembayaran'), findsOneWidget);

    // Verify summary cards
    expect(find.text('Tercocokkan'), findsOneWidget);
    expect(find.text('Selisih'), findsWidgets);
    expect(find.text('Belum Cocok'), findsWidgets);

    // Verify list items
    expect(find.text('Ref: MID-TRX-1001'), findsOneWidget);
    expect(find.text('Ref: DUIT-TRX-2002'), findsOneWidget);

    // Verify mismatch badge and warning
    expect(find.text('Penyebab: AMOUNT_MISMATCH'), findsOneWidget);

    // Verify resolve button exists for mismatched item
    expect(find.text('Selesaikan Selisih'), findsOneWidget);
  });

  testWidgets('shows resolution dialog when resolve button clicked', (
    tester,
  ) async {
    tester.view.physicalSize = const Size(1080, 1920);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(() {
      tester.view.resetPhysicalSize();
      tester.view.resetDevicePixelRatio();
    });

    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/payment-reconciliation') {
        return http.Response(
          jsonEncode({
            'status': 'success',
            'data': {
              'data': [
                {
                  'id': 'recon-2',
                  'tenant_id': 'ten-1',
                  'outlet_id': 'out-1',
                  'payment_id': 'pay-2',
                  'provider': 'duitku',
                  'provider_reference': 'DUIT-TRX-2002',
                  'internal_reference': 'INT-2002',
                  'expected_amount_minor': 100000,
                  'provider_amount_minor': 80000,
                  'fee_amount_minor': 0,
                  'net_amount_minor': 80000,
                  'currency': 'IDR',
                  'payment_status': 'settled',
                  'settlement_status': 'settled',
                  'status': 'mismatched',
                  'reconciled_at': '2026-10-09T11:00:00Z',
                  'settled_at': null,
                  'mismatch_reason': 'AMOUNT_MISMATCH',
                  'resolution_notes': null,
                  'resolved_by': null,
                  'resolved_at': null,
                  'evidence_metadata': null,
                },
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
      if (request.url.path == '/api/v1/payment-reconciliation/summary') {
        return http.Response(
          jsonEncode({
            'status': 'success',
            'data': {
              'total_reconciled': 1,
              'matched_count': 0,
              'mismatched_count': 1,
              'unmatched_count': 0,
              'needs_review_count': 0,
              'resolved_count': 0,
              'total_expected_minor': 100000,
              'total_settled_minor': 80000,
              'total_discrepancy_minor': 20000,
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
    final reconciliationService = PaymentReconciliationService(apiClient: apiClient);

    await tester.pumpWidget(
      MaterialApp(
        home: PaymentReconciliationScreen(
          reconciliationService: reconciliationService,
          canResolve: true,
        ),
      ),
    );

    await tester.pumpAndSettle();

    // Tap 'Selesaikan Selisih'
    await tester.tap(find.text('Selesaikan Selisih'));
    await tester.pumpAndSettle();

    // Verify modal dialog opened
    expect(find.text('Selesaikan Selisih'), findsWidgets);
    expect(find.text('Alasan / Catatan Penyelesaian'), findsOneWidget);
    expect(find.text('Simpan'), findsOneWidget);
  });
}
