import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/auth/auth_service.dart';
import 'package:frontend/core/auth/token_storage.dart';
import 'package:frontend/core/device/device_service.dart';
import 'package:frontend/core/tenant/tenant_service.dart';
import 'package:frontend/main.dart';
import 'package:frontend/presentation/auth/login_screen.dart';

void main() {
  late Directory tempDir;
  late DeviceService deviceService;

  setUp(() async {
    tempDir = await Directory.systemTemp.createTemp('widget_smoke_test_');
    deviceService = DeviceService(tempDir);
  });

  tearDown(() async {
    try {
      if (await tempDir.exists()) {
        await tempDir.delete(recursive: true);
      }
    } catch (_) {}
  });

  testWidgets('SeWaroeng app boots into LoginScreen when unauthenticated', (WidgetTester tester) async {
    final tokenStorage = SecureTokenStorage();
    final apiClient = ApiClient(baseUrl: 'http://localhost:8000');
    final authService = AuthService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
      deviceService: deviceService,
    );
    final tenantService = TenantService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
    );

    await tester.pumpWidget(
      SeWaroengApp(
        apiClient: apiClient,
        authService: authService,
        tenantService: tenantService,
      ),
    );

    expect(find.byType(LoginScreen), findsOneWidget);
  });
}
