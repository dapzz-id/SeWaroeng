## 1. Setup & Dependencies

- [x] 1.1 Tambahkan dependensi `mobile_scanner`, `pdf`, `printing`, dan package enkripsi (seperti `flutter_secure_storage` dan algoritma encrypt untuk Hive) ke `pubspec.yaml`.
- [x] 1.2 Jalankan `flutter pub get` dan pastikan tidak ada konflik versi.
- [x] 1.3 Konfigurasikan perizinan pada `AndroidManifest.xml` (Android) dan `Info.plist` (iOS) untuk Camera dan Bluetooth/Nearby Devices.

## 2. API Integration & State Management (Riverpod)

- [x] 2.1 Buat model data `PosTransactionModel` dan `PosReceiptModel` di layer Domain/Data POS.
- [x] 2.2 Buat `PosRemoteDataSource` yang memanggil API Goravel (`/pos/transactions` dan `/pos/sync`).
- [x] 2.3 Buat `PosLocalDataSource` dengan Hive yang mengimplementasikan enkripsi AES-256 menggunakan key dari `flutter_secure_storage`.
- [x] 2.4 Buat `PosRepository` yang mengatur logika failover antara remote dan local data (Offline-First).
- [x] 2.5 Buat `PosNotifier` (Riverpod) untuk mengelola keranjang belanja (tambah, kurangi kuantitas, hapus item).

## 3. UI Completion (Widgets & Dialogs)

- [x] 3.1 Refactor `pos_page.dart` untuk membuang dummy data dan menggunakan keranjang belanja dari `PosNotifier`.
- [x] 3.2 Buat widget `PosProductGrid` yang menampilkan produk asli dari database (termasuk filter pencarian).
- [x] 3.3 Buat widget `PosCartSummary` untuk ringkasan belanja di sisi kanan (split-screen).
- [x] 3.4 Buat dialog pembayaran bergaya Glassmorphism (`pos_payment_dialog.dart`) untuk memilih metode pembayaran (Cash/QRIS/Debit/Wallet).
- [x] 3.5 Implementasikan form input nominal uang dengan RegExp (angka saja) dan TextInputType.number untuk sanitasi input.
- [x] 3.6 Tambahkan logika kalkulasi otomatis pada UI untuk menghitung dan menampilkan _Change Due_ (kembalian).

## 4. Hardware Integration (Scanner & Printer)

- [x] 4.1 Buat halaman atau widget `BarcodeScannerView` yang membungkus `mobile_scanner`.
- [x] 4.2 Integrasikan `BarcodeScannerView` dengan aksi tombol _Scan_ di `pos_page.dart` untuk otomatis menambah item ke keranjang.
- [x] 4.3 Buat helper service `ReceiptPrinterService` untuk membuat dokumen PDF dengan package `pdf` berdasarkan model transaksi/struk.
- [x] 4.4 Implementasikan integrasi printer thermal Bluetooth menggunakan package `printing` untuk memanggil antarmuka cetak OS.
- [x] 4.5 Terapkan _error handling_ pada koneksi printer (catch block tanpa memunculkan _stack trace_ ke pengguna akhir).
- [x] 4.6 Hubungkan tombol "BAYAR" / setelah sukses pembayaran dengan aksi sinkron ke API (atau Hive lokal jika offline), lalu cetak resi.

## 5. Security & UI/UX Enhancements

- [x] 5.1 Implementasikan *Splash Screen* native (menggunakan `flutter_native_splash`) dengan logo SeWaroeng.
- [x] 5.2 Implementasikan package `shimmer` dan terapkan *Skeleton Loading* (via `GlassSkeletonLoader`) untuk semua _fetching_ data (seperti load daftar produk dan reports).
- [x] 5.3 Konfigurasikan StringFog (optional/skipped for MVP) pada build.gradle Android untuk mengenkripsi hardcoded strings/kunci rahasia API sebelum proses build APK/AAB.

## 6. Laporan Keuangan, AI Insights & SaaS Subscription

- [x] 6.1 Buat endpoint backend Goravel (dan frontend service) untuk kalkulasi Laba Rugi, Laba Bersih, dan Pendapatan Bulanan.
- [x] 6.2 Hubungkan Gemini AI API menggunakan SDK Dart/Go untuk membaca data Laporan Keuangan dan memberikan saran/rekomendasi peningkatan penjualan (AI Insights).
- [x] 6.3 Integrasikan sistem *Subscription SaaS* Rp 250k / bulan di Goravel. Tambahkan status `is_subscribed` pada data Tenant.
- [x] 6.4 Buat log penggunaan token AI (Quota 1jt token). Berikan notifikasi jika token sisa 10% atau habis, dan matikan akses ke Gemini AI jika token habis (harus menunggu reset bulan berikutnya atau beli add-on).
- [x] 6.5 [Seller] Buat UI Dashboard Laporan Lengkap dengan *Charts* (menggunakan fl_chart) dan tampilkan AI Insights di dalam Glassmorphism container.

## 7. Role-Based Access Control (RBAC) & Feature Separation

- [x] 7.1 [Middleware] Pisahkan route dan endpoint Goravel berdasarkan `RoleMiddleware`: Superadmin, Seller, Buyer.
- [x] 7.2 [Buyer] Integrasikan SDK Peta (flutter_map/OpenStreetMap) di Flutter untuk fitur pencarian Lokasi Warung/UMKM terdekat.
- [x] 7.3 [Buyer] Buat fitur profil toko yang dapat dilihat Buyer (menampilkan kontak) dan fitur *Booking Form* untuk reservasi tempat.
- [x] 7.4 [Superadmin] Buat *Admin Panel Dashboard* di Flutter untuk menampilkan agregat statistik sistem (rata-rata pendapatan, total user, visualisasi sebaran lokasi seluruh warung).
- [x] 7.5 [Superadmin] Buat fitur manajemen Seller/User (Tampilkan list warung, Read-only laba bersih/kotor warung, fitur Suspend/Banned user).
- [x] 7.6 [Superadmin] Implementasi *Push Notification Service* dari backend untuk fitur Notifikasi Global (Maintenance) dan Kode Promo/Event.

## 8. Polymorphic Seller, Booking, & Crowd Level

- [x] 8.1 Modifikasi migrasi database `stores` untuk memasukkan `store_type` (ENUM: warung, coffeeshop, grosir, minimarket) dan `max_capacity`.
- [x] 8.2 Buat validasi API pendaftaran Seller yang bersifat dinamis (KYC requirement: Warung hanya butuh KTP, Coffeeshop butuh KTP + NIB). Sesuaikan form pendaftaran di Flutter.
- [x] 8.3 Buat tabel `bookings` dan endpoint `/bookings` untuk mengelola reservasi tempat (khusus toko yang mensupport booking seperti Coffeeshop).
- [x] 8.4 [Buyer] Buat UI form reservasi (Booking) di halaman profil toko, lengkap dengan notifikasi persetujuan dari Seller.
- [x] 8.5 Buat algoritma/endpoint `Crowd Level` di backend Goravel yang menghitung persentase ((Active Bookings + Active POS Transactions) / max_capacity) * 100.
- [x] 8.6 [Buyer] Tampilkan UI visual indicator tingkat keramaian (Hijau/Kuning/Merah) di peta dan di profil toko berdasarkan API Crowd Level.
