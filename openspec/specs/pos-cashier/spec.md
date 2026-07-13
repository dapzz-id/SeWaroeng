## ADDED Requirements

### Requirement: POS Transaction Creation
Modul Kasir SHALL memungkinkan Staff/Owner membuat transaksi penjualan offline langsung dari aplikasi tanpa melalui flow order online. Modul Kasir MUST memisahkan elemen antarmuka form pembayaran ke dalam dialog yang menerapkan input sanitization untuk mencegah nilai tidak valid.

#### Scenario: POS transaction created
- **WHEN** Staff mengirimkan POST /api/v1/pos/transactions dengan daftar item, jumlah bayar, dan metode (CASH/QRIS_OFFLINE/WALLET/DEBIT)
- **THEN** sistem membuat transaksi POS, memotong stok produk dalam DB Transaction, menghitung kembalian, mengembalikan receipt data

#### Scenario: POS transaction with change calculation
- **WHEN** customer membayar Rp 100.000 untuk total belanja Rp 75.000
- **THEN** sistem menghitung kembalian Rp 25.000 dan menyertakannya dalam response

#### Scenario: Product added via barcode scanner
- **WHEN** pengguna memberikan izin kamera dan memindai barcode produk fisik melalui scanner terintegrasi
- **THEN** sistem menemukan produk yang sesuai dengan kode barcode dan secara otomatis menambahkannya ke keranjang belanja POS

### Requirement: POS Offline Sync
Aplikasi Flutter POS SHALL mendukung mode offline menggunakan local database (Hive). Kotak penyimpanan Hive yang berisi transaksi offline MUST dienkripsi dengan standar AES-256. Transaksi yang dibuat offline MUST disinkronkan ke server saat koneksi tersedia.

#### Scenario: POS works offline securely
- **WHEN** koneksi internet tidak tersedia
- **THEN** aplikasi menyimpan transaksi di Hive local DB terenkripsi dengan status PENDING_SYNC

#### Scenario: POS syncs when online
- **WHEN** koneksi internet pulih atau tombol sinkronisasi ditekan
- **THEN** aplikasi mengirimkan semua transaksi PENDING_SYNC ke server; server memproses dan mengembalikan konfirmasi

### Requirement: POS Receipt Generation
Setiap transaksi POS MUST dapat menghasilkan struk belanja dalam format yang dapat dicetak, dirancang menggunakan library pembuat PDF, lalu dikirim ke perangkat thermal printer Bluetooth eksternal. Kesalahan cetak MUST TIDAK membahayakan aplikasi atau mengekspos log sistem.

#### Scenario: Receipt data returned
- **WHEN** transaksi POS berhasil dibuat
- **THEN** sistem mengembalikan data receipt terstruktur yang dapat dirender oleh aplikasi menjadi dokumen struk PDF

#### Scenario: Print receipt success
- **WHEN** pengguna memilih untuk mencetak resi dari transaksi yang sukses
- **THEN** aplikasi merender struk dan mengirimkan perintah cetak ke thermal printer tanpa memunculkan error log ke pengguna

#### Scenario: Print receipt fails
- **WHEN** printer tidak terdeteksi atau koneksi Bluetooth terputus
- **THEN** aplikasi menahan error sistem internal dan hanya menampilkan peringatan ramah pengguna "Gagal terhubung ke printer", lalu transaksi tetap dianggap valid

### Requirement: Enhanced Mobile UI/UX & Security
Aplikasi MUST menampilkan Splash Screen saat proses inisialisasi (boot) di device mobile, menerapkan Skeleton Loading (bukan spinner/progress bar kecuali untuk file upload/download) ketika _fetching_ data, dan memproteksi kode dari *reverse engineering* sederhana via obfuskasi *StringFog* (Android).

#### Scenario: App boots with Splash Screen
- **WHEN** pengguna membuka aplikasi
- **THEN** OS mobile memunculkan Native Splash Screen hingga UI siap di-*render*

#### Scenario: Data fetches with Skeleton Loading
- **WHEN** pengguna pindah ke tab Produk atau Laporan dan data sedang di-*fetch* dari backend/lokal
- **THEN** UI merender placeholder bayangan *shimmer (Skeleton Loading)* tanpa menampilkan spinner tradisional

### Requirement: Comprehensive UMKM Reports & Gemini AI Insights
Aplikasi POS SHALL menyediakan dashboard Pelaporan komprehensif (Laba Rugi, Laba Bersih, Pendapatan Harian/Bulanan). Fitur ini MUST didukung oleh integrasi Gemini AI yang memberikan rekomendasi strategis penjualan (misal: "Produk A laku pada akhir pekan").

#### Scenario: User views comprehensive reports
- **WHEN** owner toko membuka tab Laporan
- **THEN** sistem menampilkan grafik perhitungan gross/net revenue dan laba rugi secara _real-time_

#### Scenario: User requests AI insights
- **WHEN** owner toko menekan tombol "Generate AI Insight"
- **THEN** aplikasi mengirim ringkasan data anonim ke Gemini API dan menampilkan paragraf saran strategis penjualan kepada owner (memotong kuota token IO)

### Requirement: SaaS Subscription & Token Quota Management
Aplikasi MUST mengelola status berlangganan UMKM dengan biaya langganan bulanan tetap (Rp 250.000). Setiap pembayaran/perpanjangan sukses MUST menyuntikkan (top-up) 1.000.000 token AI I/O yang dapat digunakan untuk fitur Gemini AI.

#### Scenario: Store subscribes to premium POS
- **WHEN** UMKM membayar tagihan bulanan Rp 250.000
- **THEN** status toko menjadi 'ACTIVE', sistem menambahkan 1 Juta token AI I/O, dan UMKM bisa mengakses dashboard POS penuh + AI Insights

#### Scenario: AI tokens are consumed
- **WHEN** UMKM memanggil fitur Gemini AI
- **THEN** backend memotong jumlah token yang terpakai dari saldo/kuota UMKM

