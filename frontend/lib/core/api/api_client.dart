import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:uuid/uuid.dart';

class ApiResponse<T> {
  final int statusCode;
  final T? data;
  final String? message;
  final String? code;
  final Map<String, dynamic>? errors;

  const ApiResponse({
    required this.statusCode,
    this.data,
    this.message,
    this.code,
    this.errors,
  });

  bool get isSuccess => statusCode >= 200 && statusCode < 300;
}

/// Centralized API Client responsible for authentication injection, standard headers,
/// UUIDv7 correlation IDs, and unified 401 unauthenticated session handling.
class ApiClient {
  static final Uuid _uuid = const Uuid();

  final String baseUrl;
  final http.Client _httpClient;
  final Future<String?> Function()? tokenProvider;
  final void Function()? onUnauthorized;
  String? activeTenantId;

  ApiClient({
    required this.baseUrl,
    http.Client? httpClient,
    this.tokenProvider,
    this.onUnauthorized,
    this.activeTenantId,
  }) : _httpClient = httpClient ?? http.Client();

  Map<String, String> _buildHeaders({
    String? token,
    String? idempotencyKey,
    Map<String, String>? customHeaders,
  }) {
    final headers = <String, String>{
      'Accept': 'application/json',
      'Content-Type': 'application/json',
      'X-Request-ID': _uuid.v7(),
    };

    if (token != null && token.isNotEmpty) {
      headers['Authorization'] = 'Bearer $token';
    }

    if (activeTenantId != null && activeTenantId!.isNotEmpty) {
      headers['X-Tenant-ID'] = activeTenantId!;
    }

    if (idempotencyKey != null && idempotencyKey.isNotEmpty) {
      headers['Idempotency-Key'] = idempotencyKey;
    }

    if (customHeaders != null) {
      headers.addAll(customHeaders);
    }

    return headers;
  }

  ApiResponse<T> _processResponse<T>(http.Response response) {
    if (response.statusCode == 401) {
      onUnauthorized?.call();
    }

    Map<String, dynamic>? jsonBody;
    try {
      if (response.body.isNotEmpty) {
        jsonBody = jsonDecode(response.body) as Map<String, dynamic>?;
      }
    } catch (_) {
      // Non-JSON response
    }

    return ApiResponse<T>(
      statusCode: response.statusCode,
      data: jsonBody?['data'] as T?,
      message: jsonBody?['message'] as String?,
      code: jsonBody?['code'] as String?,
      errors: (jsonBody?['errors'] is Map)
          ? jsonBody!['errors'] as Map<String, dynamic>
          : null,
    );
  }

  /// Safely resolves and normalizes request paths against baseUrl.
  /// Prevents duplicate `/api/v1` prefixes and trims redundant slashes.
  Uri buildUri(String path) {
    var base = baseUrl.trim();
    while (base.endsWith('/')) {
      base = base.substring(0, base.length - 1);
    }

    var cleanPath = path.trim();
    if (!cleanPath.startsWith('/')) {
      cleanPath = '/$cleanPath';
    }

    // Deduplicate /api/v1 if both baseUrl and cleanPath contain it
    if (base.endsWith('/api/v1') && cleanPath.startsWith('/api/v1/')) {
      cleanPath = cleanPath.substring('/api/v1'.length);
    } else if (base.endsWith('/api/v1') && cleanPath == '/api/v1') {
      cleanPath = '';
    }

    return Uri.parse('$base$cleanPath');
  }

  Future<ApiResponse<T>> get<T>(
    String path, {
    Map<String, String>? headers,
  }) async {
    final token = await tokenProvider?.call();
    final uri = buildUri(path);
    final combinedHeaders = _buildHeaders(token: token, customHeaders: headers);

    final response = await _httpClient.get(uri, headers: combinedHeaders);
    return _processResponse<T>(response);
  }

  /// Perform a raw GET request returning the full http.Response (useful for file exports).
  Future<http.Response> getRaw(
    String path, {
    Map<String, String>? headers,
  }) async {
    final token = await tokenProvider?.call();
    final uri = buildUri(path);
    final combinedHeaders = _buildHeaders(token: token, customHeaders: headers);

    return await _httpClient.get(uri, headers: combinedHeaders);
  }

  Future<ApiResponse<T>> post<T>(
    String path, {
    Object? body,
    String? idempotencyKey,
    Map<String, String>? headers,
  }) async {
    final token = await tokenProvider?.call();
    final uri = buildUri(path);
    final combinedHeaders = _buildHeaders(
      token: token,
      idempotencyKey: idempotencyKey,
      customHeaders: headers,
    );

    final payload = body is String
        ? body
        : (body != null ? jsonEncode(body) : null);
    final response = await _httpClient.post(
      uri,
      headers: combinedHeaders,
      body: payload,
    );
    return _processResponse<T>(response);
  }

  Future<ApiResponse<T>> patch<T>(
    String path, {
    Object? body,
    String? idempotencyKey,
    Map<String, String>? headers,
  }) async {
    final token = await tokenProvider?.call();
    final uri = buildUri(path);
    final combinedHeaders = _buildHeaders(
      token: token,
      idempotencyKey: idempotencyKey,
      customHeaders: headers,
    );

    final payload = body is String
        ? body
        : (body != null ? jsonEncode(body) : null);
    final response = await _httpClient.patch(
      uri,
      headers: combinedHeaders,
      body: payload,
    );
    return _processResponse<T>(response);
  }

  Future<ApiResponse<T>> put<T>(
    String path, {
    Object? body,
    String? idempotencyKey,
    Map<String, String>? headers,
  }) async {
    final token = await tokenProvider?.call();
    final uri = buildUri(path);
    final combinedHeaders = _buildHeaders(
      token: token,
      idempotencyKey: idempotencyKey,
      customHeaders: headers,
    );

    final payload = body is String
        ? body
        : (body != null ? jsonEncode(body) : null);
    final response = await _httpClient.put(
      uri,
      headers: combinedHeaders,
      body: payload,
    );
    return _processResponse<T>(response);
  }

  Future<ApiResponse<T>> delete<T>(
    String path, {
    Map<String, String>? headers,
  }) async {
    final token = await tokenProvider?.call();
    final uri = buildUri(path);
    final combinedHeaders = _buildHeaders(token: token, customHeaders: headers);

    final response = await _httpClient.delete(uri, headers: combinedHeaders);
    return _processResponse<T>(response);
  }
}
