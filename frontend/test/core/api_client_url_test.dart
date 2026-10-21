import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  group('ApiClient URL Normalization Tests', () {
    test('Correctly handles baseUrl without /api/v1 and path with /api/v1', () {
      final client = ApiClient(baseUrl: 'https://api.example.internal');
      final uri = client.buildUri('/api/v1/employees');
      expect(uri.toString(), 'https://api.example.internal/api/v1/employees');
    });

    test('Correctly handles baseUrl with /api/v1 and path with /api/v1 without doubling', () {
      final client = ApiClient(baseUrl: 'https://api.sewaroeng.neuracakrawira.asia/api/v1');
      final uri = client.buildUri('/api/v1/employees');
      expect(uri.toString(), 'https://api.sewaroeng.neuracakrawira.asia/api/v1/employees');
    });

    test('Correctly joins baseUrl and path when neither has /api/v1', () {
      final client = ApiClient(baseUrl: 'https://api.example.internal');
      final uri = client.buildUri('/outlets/123/shifts');
      expect(uri.toString(), 'https://api.example.internal/outlets/123/shifts');
    });

    test('Correctly handles baseUrl with /api/v1 and path without /api/v1', () {
      final client = ApiClient(baseUrl: 'https://api.sewaroeng.neuracakrawira.asia/api/v1');
      final uri = client.buildUri('/outlets/123/shifts');
      expect(uri.toString(), 'https://api.sewaroeng.neuracakrawira.asia/api/v1/outlets/123/shifts');
    });

    test('Strips trailing slashes from baseUrl and leading slashes from path correctly', () {
      final client = ApiClient(baseUrl: 'http://localhost:8000/api/v1///');
      final uri = client.buildUri('employees/roles');
      expect(uri.toString(), 'http://localhost:8000/api/v1/employees/roles');
    });

    test('Handles exact /api/v1 path', () {
      final client = ApiClient(baseUrl: 'https://api.sewaroeng.neuracakrawira.asia/api/v1');
      final uri = client.buildUri('/api/v1');
      expect(uri.toString(), 'https://api.sewaroeng.neuracakrawira.asia/api/v1');
    });
  });

  group('ApiClient Request Headers and Bearer Auth Tests', () {
    test('Injects required CORS headers, Bearer token, Tenant ID, and Idempotency key', () async {
      late http.BaseRequest capturedRequest;
      final mockClient = MockClient((request) async {
        capturedRequest = request;
        return http.Response('{"data":{"ok":true}}', 200, headers: {'content-type': 'application/json'});
      });

      final client = ApiClient(
        baseUrl: 'https://api.sewaroeng.neuracakrawira.asia/api/v1',
        httpClient: mockClient,
        tokenProvider: () async => 'sec-token-xyz-12345',
        activeTenantId: '0192a543-tenant-uuid',
      );

      final response = await client.post(
        '/orders',
        body: {'amount': 50000},
        idempotencyKey: 'idem-key-9999',
      );

      expect(response.isSuccess, isTrue);
      expect(capturedRequest.headers['Accept'], 'application/json');
      expect(capturedRequest.headers['Content-Type'], 'application/json');
      expect(capturedRequest.headers['Authorization'], 'Bearer sec-token-xyz-12345');
      expect(capturedRequest.headers['X-Tenant-ID'], '0192a543-tenant-uuid');
      expect(capturedRequest.headers['Idempotency-Key'], 'idem-key-9999');
      expect(capturedRequest.headers['X-Request-ID'], isNotEmpty);

      // Verify token is NOT leaked into the URL
      expect(capturedRequest.url.toString(), isNot(contains('sec-token-xyz-12345')));
    });

    test('401 response triggers onUnauthorized callback', () async {
      var unauthorizedTriggered = false;
      final mockClient = MockClient((request) async {
        return http.Response(
          '{"message":"Unauthenticated.","code":"UNAUTHENTICATED"}',
          401,
          headers: {'content-type': 'application/json'},
        );
      });

      final client = ApiClient(
        baseUrl: 'https://api.sewaroeng.neuracakrawira.asia/api/v1',
        httpClient: mockClient,
        tokenProvider: () async => 'expired-token',
        onUnauthorized: () {
          unauthorizedTriggered = true;
        },
      );

      final response = await client.get('/profile');
      expect(response.statusCode, 401);
      expect(unauthorizedTriggered, isTrue);
    });
  });
}
