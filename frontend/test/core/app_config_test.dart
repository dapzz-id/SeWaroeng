import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/config/app_config.dart';

void main() {
  group('AppConfig Tests', () {
    test('Default environment in test context is development', () {
      expect(AppConfig.environment, Environment.development);
      expect(AppConfig.isDevelopment, isTrue);
      expect(AppConfig.isProduction, isFalse);
      expect(AppConfig.isStaging, isFalse);
    });

    test('Default apiBaseUrl in development is http://localhost:8000', () {
      expect(AppConfig.apiBaseUrl, 'http://localhost:8000');
    });

    test('Validation executes without error in valid environment', () {
      expect(() => AppConfig.validate(), returnsNormally);
    });
  });
}
