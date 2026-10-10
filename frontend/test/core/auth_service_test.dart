import 'dart:convert';
import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/auth/auth_service.dart';
import 'package:frontend/core/auth/auth_state.dart';
import 'package:frontend/core/auth/token_storage.dart';
import 'package:frontend/core/device/device_service.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late Directory tempDir;
  late DeviceService deviceService;
  late InMemoryTokenStorage tokenStorage;

  setUp(() async {
    tempDir = await Directory.systemTemp.createTemp('auth_test_');
    deviceService = DeviceService(tempDir);
    tokenStorage = InMemoryTokenStorage();
  });

  tearDown(() async {
    if (await tempDir.exists()) {
      await tempDir.delete(recursive: true);
    }
  });

  test(
    'login success updates state to authenticated and persists credentials',
    () async {
      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/auth/login') {
          return http.Response(
            jsonEncode({
              'data': {
                'token': 'mock-bearer-token-123',
                'user': {
                  'id': '0192a543-0000-7000-8000-000000000001',
                  'name': 'Budi Santoso',
                  'email': 'budi@example.com',
                  'phone_number': '+6281234567890',
                  'is_active': true,
                },
                'session': {
                  'id': 'session-uuid-1',
                  'device_id': 'device-uuid-1',
                },
              },
              'message': 'Authenticated successfully.',
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(
        baseUrl: 'http://localhost:8000',
        httpClient: mockClient,
        tokenProvider: tokenStorage.getToken,
      );

      final authService = AuthService(
        apiClient: apiClient,
        tokenStorage: tokenStorage,
        deviceService: deviceService,
      );

      final success = await authService.login(
        identity: 'budi@example.com',
        password: 'Password123!',
      );

      expect(success, isTrue);
      expect(authService.state.status, equals(AuthStatus.authenticated));
      expect(authService.state.user?.name, equals('Budi Santoso'));
      expect(authService.state.token, equals('mock-bearer-token-123'));

      // Verify token persisted in secure storage
      expect(await tokenStorage.getToken(), equals('mock-bearer-token-123'));
    },
  );

  test('login failure with invalid credentials sets error state', () async {
    final mockClient = MockClient((request) async {
      return http.Response(
        jsonEncode({
          'message': 'Invalid credentials.',
          'code': 'INVALID_CREDENTIALS',
        }),
        401,
      );
    });

    final apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
      tokenProvider: tokenStorage.getToken,
    );

    final authService = AuthService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
      deviceService: deviceService,
    );

    final success = await authService.login(
      identity: 'wrong@example.com',
      password: 'WrongPassword',
    );

    expect(success, isFalse);
    expect(authService.state.status, equals(AuthStatus.error));
    expect(authService.state.errorCode, equals('INVALID_CREDENTIALS'));
    expect(await tokenStorage.getToken(), isNull);
  });

  test(
    'login failure with deactivated account maps message accurately',
    () async {
      final mockClient = MockClient((request) async {
        return http.Response(
          jsonEncode({
            'message': 'Account is deactivated. Please contact support.',
            'code': 'ACCOUNT_DEACTIVATED',
          }),
          403,
        );
      });

      final apiClient = ApiClient(
        baseUrl: 'http://localhost:8000',
        httpClient: mockClient,
        tokenProvider: tokenStorage.getToken,
      );

      final authService = AuthService(
        apiClient: apiClient,
        tokenStorage: tokenStorage,
        deviceService: deviceService,
      );

      final success = await authService.login(
        identity: 'deactivated@example.com',
        password: 'Password123!',
      );

      expect(success, isFalse);
      expect(authService.state.status, equals(AuthStatus.error));
      expect(authService.state.errorCode, equals('ACCOUNT_DEACTIVATED'));
      expect(authService.state.errorMessage, contains('deactivated'));
    },
  );

  test(
    'restoreSession validates token with server and restores authenticated state',
    () async {
      await tokenStorage.saveToken('saved-token-abc');

      final mockClient = MockClient((request) async {
        if (request.url.path == '/api/v1/auth/me') {
          expect(
            request.headers['Authorization'],
            equals('Bearer saved-token-abc'),
          );
          return http.Response(
            jsonEncode({
              'data': {
                'user': {
                  'id': 'user-123',
                  'name': 'Existing User',
                  'email': 'existing@example.com',
                  'is_active': true,
                },
              },
            }),
            200,
          );
        }
        return http.Response('Not Found', 404);
      });

      final apiClient = ApiClient(
        baseUrl: 'http://localhost:8000',
        httpClient: mockClient,
        tokenProvider: tokenStorage.getToken,
      );

      final authService = AuthService(
        apiClient: apiClient,
        tokenStorage: tokenStorage,
        deviceService: deviceService,
      );

      await authService.restoreSession();

      expect(authService.state.status, equals(AuthStatus.authenticated));
      expect(authService.state.user?.id, equals('user-123'));
    },
  );

  test('logout revokes session and clears secure storage', () async {
    await tokenStorage.saveToken('token-to-logout');

    final mockClient = MockClient((request) async {
      return http.Response(
        jsonEncode({'message': 'Token revoked successfully.'}),
        200,
      );
    });

    final apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
      tokenProvider: tokenStorage.getToken,
    );

    final authService = AuthService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
      deviceService: deviceService,
    );

    await authService.logout();

    expect(authService.state.status, equals(AuthStatus.unauthenticated));
    expect(await tokenStorage.getToken(), isNull);
  });

  test('401 unauthorized response triggers sessionExpired state', () async {
    late AuthService authService;

    final mockClient = MockClient((request) async {
      if (request.url.path == '/api/v1/auth/login') {
        return http.Response(
          jsonEncode({
            'data': {
              'token': 'active-token-123',
              'user': {
                'id': 'u-1',
                'name': 'Active User',
                'email': 'user@example.com',
                'is_active': true,
              },
              'session': {'id': 's-1', 'device_id': 'd-1'},
            },
          }),
          200,
        );
      }
      return http.Response(jsonEncode({'message': 'Unauthenticated.'}), 401);
    });

    final apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
      tokenProvider: tokenStorage.getToken,
      onUnauthorized: () => authService.handleUnauthorized(),
    );

    authService = AuthService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
      deviceService: deviceService,
    );

    // Simulate active session
    await tokenStorage.saveToken('old-token');
    await authService.login(identity: 'test@example.com', password: 'password');

    // Trigger API call returning 401
    await apiClient.get('/api/v1/some/resource');

    expect(authService.state.status, equals(AuthStatus.sessionExpired));
    expect(await tokenStorage.getToken(), isNull);
  });

  test('403 forbidden response does NOT log out user', () async {
    bool unauthorizedCalled = false;

    final mockClient = MockClient((request) async {
      return http.Response(
        jsonEncode({'message': 'Forbidden action.', 'code': 'FORBIDDEN'}),
        403,
      );
    });

    final apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
      tokenProvider: tokenStorage.getToken,
      onUnauthorized: () => unauthorizedCalled = true,
    );

    final response = await apiClient.post('/api/v1/owner/action');

    expect(response.statusCode, equals(403));
    expect(
      unauthorizedCalled,
      isFalse,
      reason: 'HTTP 403 must not trigger unauthenticated session reset',
    );
  });
}
