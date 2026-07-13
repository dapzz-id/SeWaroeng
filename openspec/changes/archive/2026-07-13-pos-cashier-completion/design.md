## Context

Backend Goravel untuk modul POS telah selesai, mencakup endpoint untuk transaksi langsung dan sinkronisasi offline. Sisi frontend Flutter masih dalam bentuk scaffold (hanya kerangka UI `pos_page.dart`). Ada kebutuhan untuk menyelesaikan integrasi *end-to-end*, menerapkan enkripsi pada penyimpanan lokal (Hive), serta menghubungkan hardware perangkat (kamera untuk barcode, dan bluetooth untuk printer thermal).

## Goals / Non-Goals

**Goals:**
- Menghubungkan Flutter frontend (POS) dengan API Goravel backend.
- Menerapkan *State Management* (Riverpod) dan arsitektur Clean (Data, Domain, Presentation).
- Menerapkan sanitasi input pada form kasir (jumlah uang diterima).
- Enkripsi database lokal Hive (AES-256) untuk transaksi offline.
- Mengintegrasikan pemindai barcode via kamera (`mobile_scanner`).
- Mengintegrasikan pencetakan struk thermal via Bluetooth (`pdf`, `printing`).
- Memecah UI menjadi *reusable widgets* bergaya Glassmorphism.
- Mengamankan source code dengan StringFog (Android).
- Menambah *Splash Screen* dan UI UX premium via Skeleton Loading.
- Membuat modul Report/Laporan Bisnis UMKM yang komprehensif.
- Memanfaatkan API Gemini AI untuk _smart recommendations_.
- Menerapkan logic penagihan bulanan / Subscription (Rp 250k) dan pengelolaan _quota_ token AI.
- Mengimplementasikan 3 level RBAC (Superadmin, Seller, Buyer) secara menyeluruh.
- Membagi entitas `Seller` menjadi beberapa *sub-role* dinamis (Warung, Coffeeshop, Minimarket, Grosir) dengan form KYC dinamis (contoh: NIB+KTP vs KTP saja).
- Menyediakan fungsionalitas **Booking Tempat** (Reservasi) dan Indikator **Crowd Level** (Tingkat Keramaian) secara *real-time*.

**Non-Goals:**
- Membuat modul Inventory lengkap (di luar skop POS saat ini).
- Mendukung metode pembayaran pihak ketiga di POS selain (Cash, QRIS manual, Wallet, Debit offline).
- Mencetak ke printer Wi-Fi/Jaringan (hanya fokus Bluetooth/Thermal).

## Decisions

- **State Management:** Menggunakan `Riverpod` karena sudah terintegrasi di project ini.
- **Local Database Encryption:** Menggunakan `hive_flutter` dengan `HiveCipher` AES-256. Key untuk enkripsi akan dihasilkan menggunakan `flutter_secure_storage` secara aman di perangkat.
- **Barcode Scanner:** Memakai package `mobile_scanner` karena merupakan standar industri modern yang dikelola komunitas, memiliki handling memory yang baik, dan cepat.
- **Bluetooth Thermal Printing:** Memakai kombinasi package `pdf` (untuk merender layout struk berlogo secara presisi) dan `printing` (untuk mencetak output PDF langsung ke hardware/sistem OS bawaan). Jika `printing` kurang cocok untuk POS (yang biasanya via command ESC/POS raw), kita akan menggunakan pendekatan PDF-to-Image / Native Print API yang didukung Flutter, atau package `esc_pos_printer` / `blue_thermal_printer` jika di perlukan (kita akan fokus pada `pdf` dan `printing` sesuai instruksi, yang mendelagasikan ke sistem spooler OS).
- **Security / Input Sanitization:** Form input akan dibatasi dengan RegExp (hanya angka) dan tipe keyboard `TextInputType.number`. Hal ini mencegah input *malicious* atau tidak valid.
- **App Security (StringFog):** Menyembunyikan hardcoded string (API keys, base URL) di sisi Android mencegah pembacaan melalui proses dekompilasi ringan.
- **UI/UX (Loading & Splash):** Menggunakan `flutter_native_splash` untuk start-up, dan widget *Shimmer* (Skeleton Loading) untuk layout UI saat _fetch_ data.
- **AI Integration (Gemini):** Memanggil API AI Gemini untuk fitur analitik/rekomendasi. Kuota token pengguna (1 Juta) dicatat pada DB backend tiap kali berlangganan/memperpanjang.
- **SaaS Subscription:** Terdapat modul pengecekan _Subscription_ (250k/bulan). Pengguna yang belum bayar akan diblokir dari fitur premium atau sinkronisasi data POS.
- **RBAC Architecture:**
  - Pengecekan JWT Role Claim di Goravel Middleware (`RoleMiddleware`).
  - **Buyer:** Mendapat akses UI peta lokasi menggunakan integrasi Maps SDK (Google/Mapbox). Fitur Direct Message direalisasikan via Websocket/SSE.
  - **Superadmin:** Modul Admin Panel di Flutter (atau via web) menggunakan aggregasi data DB untuk Global Stats (rata-rata pendapatan, jumlah user, suspend/ban) dan akses read-only ke data *Seller*.
  - **Seller (Polymorphic Roles):** Modul POS dan Laporan. Entitas *Tenant/Store* ditambahkan enum `store_type` (warung, coffeeshop, grosir, minimarket). Logic registrasi menggunakan pola *Strategy* untuk mevalidasi form KYC dinamis (KTP vs KTP+NIB).
- **Booking & Crowd Level (Real-time):** 
  - **Booking:** Entitas `Booking` yang menyimpan relasi User ke Store, beserta slot waktu dan kapasitas meja.
  - **Crowd Level:** Dikalkulasi menggunakan algoritma sederhana di backend: persentase antara *Active POS Sessions / Current Active Bookings* dibandingkan dengan *Store Capacity*. Hasil dikembalikan ke Buyer UI berupa indikator warna (Hijau: Sepi, Kuning: Sedang, Merah: Ramai).

## Risks / Trade-offs

- **[Risk] Hardware Permissions Denied:** Pengguna menolak izin kamera atau bluetooth. → **Mitigation:** Aplikasi akan menampilkan pesan UI yang jelas dan instruksi manual untuk memberikan izin, fallback ke input manual kode barcode.
- **[Risk] Print Connection Failure:** Printer bluetooth terputus saat mencetak struk. → **Mitigation:** Implementasi try-catch pada proses print, tidak *crash* aplikasi, dan menampilkan *snack bar* "Printer gagal diakses" serta opsi *print ulang* tanpa mengulangi transaksi DB.
- **[Risk] Enkripsi Key Hilang:** Jika app dihapus, *secure storage* ikut hilang sehingga Hive local tidak terbaca. → **Mitigation:** Karena ini transaksi *pending sync*, kita batasi agar sinkronisasi langsung berjalan saat online, mengurangi risiko data tertinggal lama.
