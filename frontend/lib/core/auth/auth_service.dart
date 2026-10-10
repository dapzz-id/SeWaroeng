import 'package:flutter/foundation.dart';
import '../api/api_client.dart';
import '../device/device_service.dart';
import 'auth_state.dart';
import 'token_storage.dart';

class AuthService extends ChangeNotifier {
  final ApiClient apiClient;
  final TokenStorage tokenStorage;
  final DeviceService deviceService;

  AuthState _state = AuthState.unknown();
  AuthState get state => _state;

  AuthService({
    required this.apiClient,
    required this.tokenStorage,
    required this.deviceService,
  });

  void _setState(AuthState newState) {
    _state = newState;
    notifyListeners();
  }

  /// Restores session on app launch from secure storage.
  Future<void> restoreSession() async {
    final token = await tokenStorage.getToken();
    if (token == null || token.isEmpty) {
      _setState(AuthState.unauthenticated());
      return;
    }

    try {
      final response = await apiClient.get<Map<String, dynamic>>(
        '/api/v1/auth/me',
      );

      if (response.isSuccess && response.data != null) {
        final userData = response.data!['user'] as Map<String, dynamic>;
        final user = AuthUser.fromJson(userData);
        await tokenStorage.saveUser(user.toJson());
        _setState(AuthState.authenticated(user: user, token: token));
      } else {
        await tokenStorage.clearAll();
        _setState(AuthState.unauthenticated());
      }
    } catch (_) {
      // In offline mode, use cached user credentials if available
      final cachedUserJson = await tokenStorage.getUser();
      if (cachedUserJson != null) {
        final user = AuthUser.fromJson(cachedUserJson);
        _setState(AuthState.authenticated(user: user, token: token));
      } else {
        _setState(AuthState.unauthenticated());
      }
    }
  }

  /// Authenticates user credentials against the server.
  Future<bool> login({
    required String identity,
    required String password,
  }) async {
    _setState(AuthState.authenticating());

    try {
      final deviceId = await deviceService.getOrCreateDeviceId();

      final response = await apiClient.post<Map<String, dynamic>>(
        '/api/v1/auth/login',
        body: {
          'identity': identity.trim(),
          'password': password,
          'device_id': deviceId,
          'device_name': 'SeWaroeng Mobile Client',
          'platform': 'flutter',
        },
      );

      if (response.isSuccess && response.data != null) {
        final token = response.data!['token'] as String;
        final userData = response.data!['user'] as Map<String, dynamic>;
        final user = AuthUser.fromJson(userData);

        await tokenStorage.saveToken(token);
        await tokenStorage.saveUser(user.toJson());

        _setState(AuthState.authenticated(user: user, token: token));
        return true;
      }

      final mappedMessage = _mapErrorMessage(response.code, response.message);
      _setState(AuthState.error(message: mappedMessage, code: response.code));
      return false;
    } catch (e) {
      _setState(
        AuthState.error(
          message:
              'Unable to reach the server. Please check your internet connection.',
        ),
      );
      return false;
    }
  }

  /// Terminates the active session and revokes server token.
  Future<void> logout() async {
    _setState(AuthState.loggingOut());

    try {
      await apiClient.post('/api/v1/auth/logout');
    } catch (_) {
      // Best-effort network logout
    }

    await tokenStorage.clearAll();
    _setState(AuthState.unauthenticated());
  }

  /// Handles 401 Unauthorized notifications from ApiClient.
  void handleUnauthorized() {
    if (_state.status == AuthStatus.authenticated) {
      tokenStorage.clearAll();
      _setState(AuthState.sessionExpired());
    }
  }

  String _mapErrorMessage(String? code, String? defaultMessage) {
    switch (code) {
      case 'INVALID_CREDENTIALS':
        return 'Email/phone number or password does not match.';
      case 'ACCOUNT_DEACTIVATED':
        return 'This account is deactivated. Please contact customer support.';
      case 'RATE_LIMIT_EXCEEDED':
        return defaultMessage ??
            'Too many login attempts. Please wait a moment before retrying.';
      case 'VALIDATION_ERROR':
        return defaultMessage ?? 'Please check your input and try again.';
      default:
        return defaultMessage ??
            'An unexpected error occurred. Please try again.';
    }
  }
}
