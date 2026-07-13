// Neura Cakrawira Solusi | SeWaroeng
// Auth feature - Data Layer
import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import '../../../../core/network/api_client.dart';
import '../../domain/entities/auth_user.dart';
import '../../domain/repositories/auth_repository.dart';

class AuthRepositoryImpl implements AuthRepository {
  final ApiClient _apiClient;
  final FlutterSecureStorage _storage;

  AuthRepositoryImpl({
    required ApiClient apiClient,
    required FlutterSecureStorage storage,
  })  : _apiClient = apiClient,
        _storage = storage;

  @override
  Future<AuthUser> login(LoginCredentials credentials) async {
    final response = await _apiClient.post(
      '/auth/login',
      data: {'email': credentials.email, 'password': credentials.password},
    );

    if (response.statusCode == 200) {
      final data = response.data['data'];
      final userMap = data['user'];
      final token = data['access_token'];

      // Save token & user metadata
      await _storage.write(key: 'access_token', value: token);
      await _storage.write(key: 'user_meta', value: jsonEncode(userMap));

      return AuthUser(
        id: userMap['id'],
        fullName: userMap['full_name'],
        email: userMap['email'],
        role: userMap['role'],
        tenantId: userMap['tenant_id'],
      );
    } else {
      throw Exception('Login failed: ${response.data['error']['message']}');
    }
  }

  @override
  Future<void> logout() async {
    try {
      await _apiClient.delete('/auth/logout');
    } catch (_) {
      // Ignore API errors on logout, we just clear local state
    } finally {
      await _storage.delete(key: 'access_token');
      await _storage.delete(key: 'user_meta');
    }
  }

  @override
  Future<AuthUser?> checkAuthStatus() async {
    final token = await _storage.read(key: 'access_token');
    final userMetaStr = await _storage.read(key: 'user_meta');

    if (token != null && userMetaStr != null) {
      final userMap = jsonDecode(userMetaStr);
      return AuthUser(
        id: userMap['id'],
        fullName: userMap['full_name'],
        email: userMap['email'],
        role: userMap['role'],
        tenantId: userMap['tenant_id'],
      );
    }
    return null;
  }
}
