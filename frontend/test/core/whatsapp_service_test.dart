import 'dart:convert';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/whatsapp/whatsapp_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('WhatsAppService Tests', () {
    test('fetchConnection parses connection and providers successfully', () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/whatsapp/connection' && request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'data': {
                'connections': [
                  {
                    'id': 'conn-1',
                    'tenant_id': 'tenant-1',
                    'provider': 'meta_cloud',
                    'environment': 'sandbox',
                    'status': 'active',
                    'is_default': true,
                    'phone_number_id': 'phone-123',
                    'business_account_id': 'biz-456',
                    'verification_status': 'verified',
                    'masked_credentials': {'access_token': 'EAA***', 'app_secret': 'sec***'},
                    'verified_at': '2026-10-09T10:00:00Z',
                  },
                ],
                'available_providers': [
                  {
                    'id': 'p-1',
                    'provider': 'meta_cloud',
                    'display_name': 'Meta WhatsApp Cloud API',
                    'is_enabled': true,
                  },
                  {
                    'id': 'p-2',
                    'provider': 'mock',
                    'display_name': 'Mock Provider',
                    'is_enabled': true,
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
      final service = WhatsAppService(apiClient: apiClient);

      await service.fetchConnection();

      expect(service.connection, isNotNull);
      expect(service.connection!.provider, equals('meta_cloud'));
      expect(service.connection!.isVerified, isTrue);
      expect(service.connection!.isActive, isTrue);
      expect(service.connection!.maskedCredentials.containsKey('access_token'), isTrue);
      expect(service.providers.length, equals(2));
      expect(service.errorMessage, isNull);
    });

    test('saveConnection and verifyConnection work properly', () async {
      bool saveCalled = false;
      bool verifyCalled = false;

      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/whatsapp/connection' && request.method == 'POST') {
          saveCalled = true;
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          expect(body['provider'], equals('meta_cloud'));
          return http.Response(
            jsonEncode({
              'message': 'Connection saved',
              'data': {
                'id': 'conn-1',
                'tenant_id': 'tenant-1',
                'provider': 'meta_cloud',
                'environment': 'sandbox',
                'status': 'active',
                'is_default': true,
                'verification_status': 'unverified',
                'masked_credentials': {},
              },
            }),
            200,
          );
        }
        if (request.url.path == '/api/v1/whatsapp/connection' && request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'data': {
                'connections': [
                  {
                    'id': 'conn-1',
                    'tenant_id': 'tenant-1',
                    'provider': 'meta_cloud',
                    'environment': 'sandbox',
                    'status': 'active',
                    'is_default': true,
                    'verification_status': 'verified',
                    'masked_credentials': {},
                  },
                ],
                'available_providers': [],
              },
            }),
            200,
          );
        }
        if (request.url.path == '/api/v1/whatsapp/connection/verify' && request.method == 'POST') {
          verifyCalled = true;
          return http.Response(
            jsonEncode({
              'data': {
                'verified': true,
                'phone_number_id': '12345',
              },
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
      final service = WhatsAppService(apiClient: apiClient);

      final saveResult = await service.saveConnection(
        provider: 'meta_cloud',
        environment: 'sandbox',
        credentials: {'access_token': 'secret-token'},
      );
      expect(saveResult, isTrue);
      expect(saveCalled, isTrue);

      final verifyResult = await service.verifyConnection();
      expect(verifyResult, isNotNull);
      expect(verifyResult!['verified'], isTrue);
      expect(verifyCalled, isTrue);
    });

    test('sendTestMessage and sendReceipt work properly', () async {
      String? sentPhone;
      String? receiptSaleId;

      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/whatsapp/connection/test' && request.method == 'POST') {
          final body = jsonDecode(request.body) as Map<String, dynamic>;
          sentPhone = body['recipient_phone'] as String?;
          return http.Response(jsonEncode({'message': 'Test sent'}), 200);
        }
        if (request.url.path.startsWith('/api/v1/whatsapp/receipt/') && request.method == 'POST') {
          receiptSaleId = request.url.pathSegments.reversed.skip(1).first;
          return http.Response(jsonEncode({'message': 'Receipt queued'}), 200);
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
      final service = WhatsAppService(apiClient: apiClient);

      final testOk = await service.sendTestMessage('081234567890');
      expect(testOk, isTrue);
      expect(sentPhone, equals('081234567890'));

      final receiptOk = await service.sendReceipt(saleId: 'sale-999', phone: '081234567890');
      expect(receiptOk, isTrue);
      expect(receiptSaleId, equals('sale-999'));
    });

    test('fetchMessages and resendMessage work properly', () async {
      bool resendCalled = false;

      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/whatsapp/messages' && request.method == 'GET') {
          return http.Response(
            jsonEncode({
              'data': [
                {
                  'id': 'msg-1',
                  'recipient_phone': '6281234567890',
                  'message_type': 'receipt',
                  'status': 'failed',
                  'provider': 'meta_cloud',
                  'error_message': 'Rate limit exceeded',
                  'created_at': '2026-10-09T10:00:00Z',
                },
              ],
            }),
            200,
          );
        }
        if (request.url.path == '/api/v1/whatsapp/messages/msg-1/resend' && request.method == 'POST') {
          resendCalled = true;
          return http.Response(jsonEncode({'message': 'Resent'}), 200);
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(baseUrl: 'http://localhost:8000', httpClient: mockClient);
      final service = WhatsAppService(apiClient: apiClient);

      await service.fetchMessages();
      expect(service.messages.length, equals(1));
      expect(service.messages.first.status, equals('failed'));
      expect(service.messages.first.recipientPhone, equals('6281234567890'));

      final resendOk = await service.resendMessage('msg-1');
      expect(resendOk, isTrue);
      expect(resendCalled, isTrue);
    });
  });
}
