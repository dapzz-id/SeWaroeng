// Neura Cakrawira Solusi | SeWaroeng
// ApiClient — Dio wrapper for HTTP requests with auth interception and token refresh logic.
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

final apiClientProvider = Provider<ApiClient>((ref) {
  // Use a provider for secure storage or just instantiate it
  const storage = FlutterSecureStorage();
  // Assume a default base URL or get it from environment variables
  return ApiClient(baseUrl: 'http://localhost:3000/api', storage: storage);
});

class ApiClient {
  final Dio _dio;
  final FlutterSecureStorage _storage;

  ApiClient({
    required String baseUrl,
    required FlutterSecureStorage storage,
  })  : _storage = storage,
        _dio = Dio(BaseOptions(
          baseUrl: baseUrl,
          connectTimeout: const Duration(seconds: 15),
          receiveTimeout: const Duration(seconds: 15),
          headers: {'Content-Type': 'application/json'},
        )) {
    _setupInterceptors();
  }

  void _setupInterceptors() {
    _dio.interceptors.add(
      InterceptorsWrapper(
        onRequest: (options, handler) async {
          // Inject access token if available
          final token = await _storage.read(key: 'access_token');
          if (token != null) {
            options.headers['Authorization'] = 'Bearer $token';
          }
          return handler.next(options);
        },
        onError: (DioException e, handler) async {
          // Handle 401 Unauthorized (Token Expired)
          if (e.response?.statusCode == 401) {
            // Check if error is specifically about expiration
            final errorCode = e.response?.data?['error']?['code'];
            if (errorCode == 'TOKEN_EXPIRED') {
              final isRefreshed = await _refreshToken();
              if (isRefreshed) {
                // Retry original request with new token
                try {
                  final token = await _storage.read(key: 'access_token');
                  e.requestOptions.headers['Authorization'] = 'Bearer $token';
                  final response = await _dio.fetch(e.requestOptions);
                  return handler.resolve(response);
                } catch (retryError) {
                  return handler.next(e);
                }
              } else {
                // Force logout if refresh fails
                await _storage.delete(key: 'access_token');
                // Note: Router redirection should be handled by an auth state listener
              }
            }
          }
          return handler.next(e);
        },
      ),
    );
  }

  Future<bool> _refreshToken() async {
    try {
      // The refresh token is in an HTTP-only cookie, so we just call the endpoint.
      // Dio will automatically include cookies if configured, or the browser/platform does it.
      // For mobile, we might need a cookie manager like dio_cookie_manager, 
      // but assuming the backend handles refresh, we call it.
      final response = await _dio.post('/auth/refresh');
      if (response.statusCode == 200) {
        final newToken = response.data['data']['access_token'];
        await _storage.write(key: 'access_token', value: newToken);
        return true;
      }
      return false;
    } catch (_) {
      return false;
    }
  }

  // ── Exposed Methods ────────────────────────────────────────────────────────
  
  Future<Response<T>> get<T>(String path, {Map<String, dynamic>? queryParameters}) {
    return _dio.get<T>(path, queryParameters: queryParameters);
  }

  Future<Response<T>> post<T>(String path, {dynamic data}) {
    return _dio.post<T>(path, data: data);
  }

  Future<Response<T>> put<T>(String path, {dynamic data}) {
    return _dio.put<T>(path, data: data);
  }

  Future<Response<T>> delete<T>(String path, {dynamic data}) {
    return _dio.delete<T>(path, data: data);
  }
}
