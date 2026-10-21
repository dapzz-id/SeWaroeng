enum Environment {
  development,
  staging,
  production,
}

/// Centralized, fail-closed configuration provider for the SeWaroeng Flutter client.
/// Validates compile-time environment flags (--dart-define) and enforces security boundaries.
class AppConfig {
  static const String _rawEnv = String.fromEnvironment('APP_ENV', defaultValue: 'development');
  static const String _rawApiUrl = String.fromEnvironment('API_BASE_URL', defaultValue: '');
  static const bool _rawEnableLogging = bool.fromEnvironment('ENABLE_LOGGING', defaultValue: false);

  /// Resolves the typed environment or throws if an unrecognized value is passed.
  static Environment get environment {
    switch (_rawEnv.toLowerCase().trim()) {
      case 'production':
      case 'prod':
        return Environment.production;
      case 'staging':
      case 'stage':
        return Environment.staging;
      case 'development':
      case 'dev':
      case 'local':
        return Environment.development;
      default:
        throw StateError(
          'Invalid APP_ENV: "$_rawEnv". Permitted values: development, staging, production.',
        );
    }
  }

  static bool get isProduction => environment == Environment.production;
  static bool get isStaging => environment == Environment.staging;
  static bool get isDevelopment => environment == Environment.development;

  /// Development logging is strictly suppressed in production builds.
  static bool get enableLogging => !isProduction && _rawEnableLogging;

  /// Validates and returns the canonical API Base URL.
  /// Throws a descriptive StateError on missing or insecure configurations.
  static String get apiBaseUrl {
    final env = environment;

    // Staging and Production both require an explicit API_BASE_URL passed at build time.
    if ((env == Environment.production || env == Environment.staging) &&
        _rawApiUrl.trim().isEmpty) {
      throw StateError(
        'Fail-Closed Build Error: API_BASE_URL must be explicitly provided for $env builds via '
        '--dart-define=API_BASE_URL=https://<api-domain>. It cannot be omitted or defaulted.',
      );
    }

    final effectiveUrl = _rawApiUrl.trim().isNotEmpty
        ? _rawApiUrl.trim()
        : 'http://localhost:8000';

    final uri = Uri.tryParse(effectiveUrl);
    if (uri == null || !uri.hasScheme || !uri.hasAuthority) {
      throw StateError(
        'Invalid API_BASE_URL: "$effectiveUrl". Must be a valid absolute URI (e.g. https://api.domain.com).',
      );
    }

    // In staging and production, non-HTTPS protocols are strictly rejected.
    if ((env == Environment.staging || env == Environment.production) && uri.scheme != 'https') {
      throw StateError(
        'Security Error: Insecure HTTP protocol detected for $env build: "$effectiveUrl". '
        'Staging and Production builds must use HTTPS.',
      );
    }

    // Strip trailing slashes for consistent path composition
    var clean = effectiveUrl;
    while (clean.endsWith('/')) {
      clean = clean.substring(0, clean.length - 1);
    }

    return clean;
  }

  /// Eager initialization validation. Call in main() to abort app startup immediately if misconfigured.
  static void validate() {
    // Forces evaluation of getters
    final env = environment;
    final url = apiBaseUrl;
    if (url.isEmpty || env.toString().isEmpty) {
      throw StateError('AppConfig validation failed.');
    }
  }
}
