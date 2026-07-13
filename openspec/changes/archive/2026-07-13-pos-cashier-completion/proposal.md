## Why

Fitur Kasir (POS) di aplikasi SeWaroeng saat ini baru selesai di sisi backend (logika transaksi, stok, dll). Di sisi frontend Flutter, antarmuka POS masih menggunakan dummy data dan belum terhubung ke API backend. Perubahan ini diperlukan untuk menyelesaikan alur bisnis POS secara menyeluruh (End-to-End), menghubungkan frontend dengan backend, dan mengaktifkan integrasi perangkat keras penting seperti pemindai barcode kamera dan pencetakan struk thermal Bluetooth, dengan tetap mempertahankan standar keamanan (OWASP) dan arsitektur yang bersih (Clean Architecture).

## What Changes

- Menghapus dummy data pada halaman POS (`pos_page.dart`).
- Membuat implementasi Data Layer (Repository & Remote/Local DataSource) untuk POS.
- Membuat State Management (Provider/Riverpod) untuk POS yang menghubungkan UI dengan API backend (endpoint `/pos/transactions` & `/pos/sync`).
- Mengimplementasikan sinkronisasi transaksi offline lokal (Hive) dengan enkripsi AES-256 untuk melindungi data sensitif UMKM.
- Melengkapi komponen UI POS (dialog pemilihan metode pembayaran, input nominal, perhitungan kembalian otomatis) dengan desain Glassmorphism.
- Memecah komponen antarmuka POS menjadi _reusable widgets_ pada `pos/presentation/widgets/`.
- Menerapkan sanitasi input yang ketat pada form pembayaran untuk mencegah kerentanan.
- Mengintegrasikan pemindai barcode kamera (`mobile_scanner`) untuk memasukkan produk ke keranjang POS.
- Mengintegrasikan sistem pencetakan struk thermal Bluetooth (`pdf` dan `printing`) dengan penanganan _error_ yang baik agar tidak menyebabkan _crash_ atau kebocoran log.
- Mengamankan source code Android menggunakan **StringFog** untuk string obfuscation.
- Menambahkan **Splash Screen** _native_ untuk memoles tampilan saat startup.
- Menggunakan pola **Skeleton Loading** saat _fetching_ data (seperti daftar produk atau laporan), dengan *progress bar* hanya dipakai saat upload/download.
- Mengimplementasikan modul **Laporan Analitik UMKM (Comprehensive Reports)** yang menampilkan Laba Rugi, Laba Bersih, dan Pendapatan secara mendetail.
- Mengintegrasikan **Gemini AI API** untuk memberikan wawasan pintar (rekomendasi penjualan, tren barang laku, dll).
- Menerapkan **Sistem Berlangganan (Subscription)** sebesar Rp 250.000/bulan, dengan benefit kuota akses token AI I/O sebanyak 1 Juta token.
- Mengimplementasikan **Sistem RBAC (Role-Based Access Control) 3 Level**:
  - **User Biasa (Buyer):** Dapat melakukan order, melihat Peta Lokasi Warung, melihat detail/kontak pemilik warung, dan fitur Direct Message.
  - **Seller (UMKM/Minimarket/Grosir/Coffeeshop):** Akses penuh ke POS, manajemen stok, dan laporan komprehensif UMKM. Dibagi menjadi beberapa sub-peran dengan syarat pendaftaran yang berbeda (misal: Coffeeshop butuh NIB+KTP, Warung hanya KTP) dan modul fitur yang berbeda.
- Mengimplementasikan **Fitur Booking Tempat** agar Buyer dapat mereservasi meja/tempat (terutama untuk tipe Coffeeshop/Resto).
- Mengimplementasikan **Pengecekan Tingkat Keramaian (Crowd Level)** secara real-time agar pengguna dapat mengetahui apakah sebuah tempat sedang ramai atau sepi.

## Capabilities

### New Capabilities
*(Tidak ada capability baru di level spesifikasi yang di-introduce, hanya melengkapi implementasi dari capability yang sudah ada)*

### Modified Capabilities
- `pos-cashier`: Penambahan rincian skenario penggunaan untuk sinkronisasi offline (dengan enkripsi), integrasi pemindai barcode (kamera), pencetakan struk thermal (hardware eksternal), pelaporan mendalam (Laba Rugi UMKM), analitik AI (Gemini), dan penagihan langganan (Subscription).
- `auth-security` & `user-management`: Penambahan hierarki RBAC 3 peran (Buyer, Superadmin, Seller), pemetaan lokasi (Maps) untuk Buyer & Superadmin, fitur Direct Message, dan kontrol administratif tingkat tinggi (Suspend, Banned, Global Notif).
- `tenant-management`: Modifikasi arsitektur Seller menjadi bentuk polimorfik (sub-roles: Warung, Coffeeshop, Minimarket, Grosir) dengan form KYC dinamis. Penambahan fitur Booking Tempat dan Cek Keramaian (Crowd Level).

## Impact

- **Frontend (Flutter)**: Perubahan signifikan pada direktori `lib/features/pos/` (Presentation, Domain, dan Data layers). Penambahan dependensi baru di `pubspec.yaml` (`mobile_scanner`, `pdf`, `printing`, serta enkripsi untuk Hive/Storage).
- **Keamanan**: Perubahan pada cara penyimpanan offline di Flutter untuk memastikan enkripsi menggunakan standar AES-256.
- **Hardware/Permissions**: Aplikasi akan meminta izin akses Kamera (untuk barcode) dan Bluetooth/Nearby Devices (untuk pencetak struk thermal).
