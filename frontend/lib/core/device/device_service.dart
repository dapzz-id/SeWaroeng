import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:uuid/uuid.dart';

/// Service responsible for managing a stable, installation-level device identifier.
///
/// Complies with ADR-0003 & ADR-0004:
/// - Not based on hardware MAC/IMEI (privacy compliant).
/// - Stable across application restarts.
/// - Fresh identifier generated on application re-install.
/// - Never used as an authentication secret (only as device session identifier).
class DeviceService {
  static const String _deviceIdFileName = 'sewaroeng_device.id';
  static final Uuid _uuid = const Uuid();

  final Directory? _storageDirectory;
  String? _cachedDeviceId;

  DeviceService([this._storageDirectory]);

  /// Returns the persistent device ID (UUIDv7 format).
  /// Generates a new UUIDv7 if one does not already exist.
  Future<String> getOrCreateDeviceId() async {
    if (_cachedDeviceId != null) {
      return _cachedDeviceId!;
    }

    if (!kIsWeb && _storageDirectory != null) {
      try {
        final dir = _storageDirectory;
        final file = File('${dir.path}/$_deviceIdFileName');
        if (await file.exists()) {
          final content = (await file.readAsString()).trim();
          if (content.isNotEmpty) {
            _cachedDeviceId = content;
            return _cachedDeviceId!;
          }
        }

        // Generate new UUIDv7
        final newId = _uuid.v7();
        await file.writeAsString(newId);
        _cachedDeviceId = newId;
        return newId;
      } catch (_) {}
    }

    final fallbackId = _uuid.v7();
    _cachedDeviceId = fallbackId;
    return fallbackId;
  }

  /// Resets the device identifier (e.g. on complete wipe/factory reset)
  Future<void> resetDeviceId() async {
    if (!kIsWeb && _storageDirectory != null) {
      try {
        final dir = _storageDirectory;
        final file = File('${dir.path}/$_deviceIdFileName');
        if (await file.exists()) {
          await file.delete();
        }
      } catch (_) {}
    }
    _cachedDeviceId = null;
  }
}
