// Neura Cakrawira Solusi | SeWaroeng
// DeviceCapabilityService — detects device capabilities to optimize UI rendering.
import 'dart:io';
import 'package:flutter/foundation.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

/// Provides device capability status for conditional UI rendering.
class DeviceCapabilityService {
  /// Whether the device can handle heavy glassmorphism effects (BackdropFilter).
  /// Falls back to opaque or semi-transparent colors on low-end devices.
  bool get supportsGlassEffects {
    if (kIsWeb) return true;
    
    // In a real app, this would use device_info_plus to check RAM/CPU.
    // For MVP, we enable it generally but provide the flag for toggle.
    if (Platform.isAndroid || Platform.isIOS) {
      // Simulate detection: assume capable unless explicitly limited
      return true;
    }
    
    return true; // Desktop/Web
  }
}

final deviceCapabilityProvider = Provider<DeviceCapabilityService>((ref) {
  return DeviceCapabilityService();
});
