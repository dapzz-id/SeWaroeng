import 'dart:io';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/api/api_client.dart';
import 'package:frontend/core/auth/auth_service.dart';
import 'package:frontend/core/auth/auth_state.dart';
import 'package:frontend/core/auth/token_storage.dart';
import 'package:frontend/core/device/device_service.dart';
import 'package:frontend/presentation/auth/login_screen.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

void main() {
  late Directory tempDir;
  late DeviceService deviceService;
  late InMemoryTokenStorage tokenStorage;

  setUp(() async {
    tempDir = await Directory.systemTemp.createTemp('widget_auth_test_');
    deviceService = DeviceService(tempDir);
    tokenStorage = InMemoryTokenStorage();
  });

  tearDown(() async {
    try {
      if (await tempDir.exists()) {
        await tempDir.delete(recursive: true);
      }
    } catch (_) {}
  });

  testWidgets('renders login screen components', (tester) async {
    final apiClient = ApiClient(baseUrl: 'http://localhost:8000');
    final authService = AuthService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
      deviceService: deviceService,
    );

    await tester.pumpWidget(
      MaterialApp(home: LoginScreen(authService: authService)),
    );

    expect(find.text('SeWaroeng'), findsOneWidget);
    expect(find.byKey(const Key('login_identity_input')), findsOneWidget);
    expect(find.byKey(const Key('login_password_input')), findsOneWidget);
    expect(find.byKey(const Key('login_submit_button')), findsOneWidget);
  });

  testWidgets('validates required fields before submitting', (tester) async {
    final apiClient = ApiClient(baseUrl: 'http://localhost:8000');
    final authService = AuthService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
      deviceService: deviceService,
    );

    await tester.pumpWidget(
      MaterialApp(home: LoginScreen(authService: authService)),
    );

    // Tap submit with empty inputs
    await tester.tap(find.byKey(const Key('login_submit_button')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(find.text('Harap masukkan email atau nomor HP.'), findsOneWidget);
    expect(find.text('Harap masukkan kata sandi.'), findsOneWidget);
  });

  testWidgets('displays error banner when authentication fails', (
    tester,
  ) async {
    final mockClient = MockClient((request) async {
      return http.Response(
        '{"message": "Invalid credentials.", "code": "INVALID_CREDENTIALS"}',
        401,
      );
    });

    final apiClient = ApiClient(
      baseUrl: 'http://localhost:8000',
      httpClient: mockClient,
    );

    final authService = AuthService(
      apiClient: apiClient,
      tokenStorage: tokenStorage,
      deviceService: deviceService,
    );

    await tester.pumpWidget(
      MaterialApp(home: LoginScreen(authService: authService)),
    );

    await tester.enterText(
      find.byKey(const Key('login_identity_input')),
      'wrong@example.com',
    );
    await tester.enterText(
      find.byKey(const Key('login_password_input')),
      'WrongPassword123',
    );

    await tester.runAsync(() async {
      await tester.tap(find.byKey(const Key('login_submit_button')));
      for (int i = 0; i < 50; i++) {
        if (authService.state.status != AuthStatus.authenticating &&
            authService.state.status != AuthStatus.unknown) {
          break;
        }
        await Future.delayed(const Duration(milliseconds: 20));
      }
    });
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 100));

    expect(authService.state.status, equals(AuthStatus.error));
    expect(find.byKey(const Key('login_error_message')), findsOneWidget);
    expect(
      find.text('Email/phone number or password does not match.'),
      findsOneWidget,
    );
  });
}
