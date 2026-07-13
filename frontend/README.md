# SeWaroeng Frontend (Flutter)

Aplikasi klien SeWaroeng yang responsif dan cross-platform, diimplementasikan dengan arsitektur Riverpod (Clean Architecture) dan UI bergaya Liquid Glass.

## Requirements
- Flutter SDK >= 3.4.0
- Dart SDK >= 3.4.0

## Arsitektur & State Management
- **Routing:** go_router (ShellRoute untuk Bottom Navigation).
- **State Management:** Riverpod 2.x dengan Riverpod Generator.
- **Data Persistence:** Hive (untuk transaksi POS offline) & FlutterSecureStorage (JWT).
- **Networking:** Dio dengan interceptor auth & token refresh.

## Setup Instruksi

1. **Install Dependencies**
   ```bash
   flutter pub get
   ```

2. **Generate Code**
   Untuk Freezed models dan Riverpod providers:
   ```bash
   flutter pub run build_runner build --delete-conflicting-outputs
   ```

3. **Menjalankan App**
   Untuk development mode (Android/iOS/Web):
   ```bash
   flutter run
   ```

## Fitur Khusus
- **Liquid Glass UI:** Menggunakan `GlassContainer` (`BackdropFilter` & `RepaintBoundary`). UI ini responsif terhadap kapabilitas GPU perangkat (fallback otomatis menjadi *solid color* di device *low-end* via `DeviceCapabilityService`).
- **Offline POS:** Transaksi Kasir disimpan ke Hive dan disinkronisasi ke backend saat jaringan tersedia (`/api/v1/pos/sync`).
