import 'dart:io';
import 'package:flutter_test/flutter_test.dart';
import 'package:frontend/core/device/device_service.dart';

void main() {
  late Directory tempDir;
  late DeviceService deviceService;

  setUp(() async {
    tempDir = await Directory.systemTemp.createTemp('sewaroeng_device_test_');
    deviceService = DeviceService(tempDir);
  });

  tearDown(() async {
    if (await tempDir.exists()) {
      await tempDir.delete(recursive: true);
    }
  });

  test('generates a valid UUIDv7 device ID on first call', () async {
    final deviceId = await deviceService.getOrCreateDeviceId();

    expect(deviceId, isNotEmpty);
    // UUID regex format: 8-4-4-4-12 hex characters
    final uuidRegex = RegExp(
      r'^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$',
      caseSensitive: false,
    );
    expect(uuidRegex.hasMatch(deviceId), isTrue);
  });

  test('persists device ID across multiple calls', () async {
    final firstId = await deviceService.getOrCreateDeviceId();
    final secondId = await deviceService.getOrCreateDeviceId();

    expect(secondId, equals(firstId));

    // Simulate app restart with a fresh service instance pointing to same directory
    final restartedService = DeviceService(tempDir);
    final restartedId = await restartedService.getOrCreateDeviceId();

    expect(restartedId, equals(firstId));
  });

  test(
    'resetDeviceId clears device ID and creates new one on next call',
    () async {
      final initialId = await deviceService.getOrCreateDeviceId();
      await deviceService.resetDeviceId();

      final newId = await deviceService.getOrCreateDeviceId();
      expect(newId, isNot(equals(initialId)));
    },
  );
}
