import 'dart:convert';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

/// Contract for secure token and credential persistence.
abstract class TokenStorage {
  Future<void> saveToken(String token);
  Future<String?> getToken();
  Future<void> clearToken();

  Future<void> saveUser(Map<String, dynamic> userJson);
  Future<Map<String, dynamic>?> getUser();

  Future<void> saveActiveTenantId(String? tenantId);
  Future<String?> getActiveTenantId();

  Future<void> saveActiveOutletId(String? outletId);
  Future<String?> getActiveOutletId();

  Future<void> clearAll();
}

/// Production implementation using platform secure storage (Keychain on iOS/macOS, Keystore/EncryptedSharedPreferences on Android).
class SecureTokenStorage implements TokenStorage {
  static const _tokenKey = 'sewaroeng_auth_token';
  static const _userKey = 'sewaroeng_auth_user';
  static const _activeTenantKey = 'sewaroeng_active_tenant_id';
  static const _activeOutletKey = 'sewaroeng_active_outlet_id';

  final FlutterSecureStorage _storage;

  SecureTokenStorage([FlutterSecureStorage? storage])
    : _storage = storage ?? const FlutterSecureStorage();

  @override
  Future<void> saveToken(String token) async {
    await _storage.write(key: _tokenKey, value: token);
  }

  @override
  Future<String?> getToken() async {
    return _storage.read(key: _tokenKey);
  }

  @override
  Future<void> clearToken() async {
    await _storage.delete(key: _tokenKey);
  }

  @override
  Future<void> saveUser(Map<String, dynamic> userJson) async {
    await _storage.write(key: _userKey, value: jsonEncode(userJson));
  }

  @override
  Future<Map<String, dynamic>?> getUser() async {
    final raw = await _storage.read(key: _userKey);
    if (raw == null) return null;
    try {
      return jsonDecode(raw) as Map<String, dynamic>;
    } catch (_) {
      return null;
    }
  }

  @override
  Future<void> saveActiveTenantId(String? tenantId) async {
    if (tenantId == null) {
      await _storage.delete(key: _activeTenantKey);
    } else {
      await _storage.write(key: _activeTenantKey, value: tenantId);
    }
  }

  @override
  Future<String?> getActiveTenantId() async {
    return _storage.read(key: _activeTenantKey);
  }

  @override
  Future<void> saveActiveOutletId(String? outletId) async {
    if (outletId == null) {
      await _storage.delete(key: _activeOutletKey);
    } else {
      await _storage.write(key: _activeOutletKey, value: outletId);
    }
  }

  @override
  Future<String?> getActiveOutletId() async {
    return _storage.read(key: _activeOutletKey);
  }

  @override
  Future<void> clearAll() async {
    await _storage.delete(key: _tokenKey);
    await _storage.delete(key: _userKey);
    await _storage.delete(key: _activeTenantKey);
    await _storage.delete(key: _activeOutletKey);
  }
}

/// In-memory implementation used for headless unit tests.
class InMemoryTokenStorage implements TokenStorage {
  String? _token;
  Map<String, dynamic>? _user;
  String? _activeTenantId;
  String? _activeOutletId;

  @override
  Future<void> saveToken(String token) async {
    _token = token;
  }

  @override
  Future<String?> getToken() async {
    return _token;
  }

  @override
  Future<void> clearToken() async {
    _token = null;
  }

  @override
  Future<void> saveUser(Map<String, dynamic> userJson) async {
    _user = userJson;
  }

  @override
  Future<Map<String, dynamic>?> getUser() async {
    return _user;
  }

  @override
  Future<void> saveActiveTenantId(String? tenantId) async {
    _activeTenantId = tenantId;
  }

  @override
  Future<String?> getActiveTenantId() async {
    return _activeTenantId;
  }

  @override
  Future<void> saveActiveOutletId(String? outletId) async {
    _activeOutletId = outletId;
  }

  @override
  Future<String?> getActiveOutletId() async {
    return _activeOutletId;
  }

  @override
  Future<void> clearAll() async {
    _token = null;
    _user = null;
    _activeTenantId = null;
    _activeOutletId = null;
  }
}
