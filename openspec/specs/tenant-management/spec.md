## ADDED Requirements

### Requirement: Tenant Registration
Sistem SHALL memungkinkan UMKM mendaftarkan diri sebagai tenant baru dengan data usaha lengkap. Setiap tenant MUST mendapatkan UUID unik (`tenant_id`) yang menjadi diskriminator data di seluruh tabel.

#### Scenario: Tenant registers successfully
- **WHEN** UMKM mengirimkan POST /api/v1/tenants dengan data nama usaha, alamat, kontak, dan email valid
- **THEN** sistem membuat record tenant baru dengan UUID v7 sebagai tenant_id, mengembalikan 201 Created dengan data tenant

#### Scenario: Duplicate email rejected
- **WHEN** UMKM mendaftarkan email yang sudah terdaftar sebagai tenant lain
- **THEN** sistem mengembalikan 409 Conflict dengan pesan error yang jelas

### Requirement: Tenant Data Isolation
Sistem SHALL memastikan setiap query data yang bersifat tenant-aware secara otomatis difilter berdasarkan tenant_id dari context request. Tidak ada data tenant A yang boleh dibaca atau dimodifikasi oleh tenant B.

#### Scenario: Cross-tenant data access blocked
- **WHEN** tenant A mencoba mengakses resource dengan ID milik tenant B
- **THEN** sistem mengembalikan 404 Not Found (bukan 403, untuk mencegah enumeration attack)

#### Scenario: Middleware auto-injects tenant_id
- **WHEN** setiap request API masuk dengan Access Token valid
- **THEN** middleware otomatis mengekstrak tenant_id dari JWT claim dan menginject ke request context

### Requirement: Tenant Store Configuration
Setiap tenant SHALL dapat mengkonfigurasi profil toko mereka: nama toko, logo, deskripsi, jam operasional, alamat lengkap, dan koordinat lokasi (GEOGRAPHY point untuk PostGIS).

#### Scenario: Store profile update
- **WHEN** tenant owner mengirimkan PUT /api/v1/store/profile dengan data valid
- **THEN** sistem mengupdate data toko dan menginvalidasi cache Redis untuk store profile

### Requirement: Tenant Status Management
Sistem SHALL mendukung status tenant: active, suspended, pending_verification. Tenant dengan status suspended MUST diblok dari seluruh operasi bisnis.

#### Scenario: Suspended tenant blocked
- **WHEN** tenant dengan status suspended mencoba melakukan operasi apapun
- **THEN** middleware mengembalikan 403 Forbidden dengan pesan "Akun toko Anda sedang ditangguhkan"

### Requirement: Polymorphic Seller Roles & Dynamic KYC
Sistem MUST mendukung klasifikasi tipe toko (Store Type / Seller Sub-role) seperti `warung`, `coffeeshop`, `grosir`, dan `minimarket`. Pendaftaran Seller MUST menyesuaikan persyaratan dokumen (KYC) secara dinamis sesuai dengan tipe toko yang dipilih.

#### Scenario: Registering as a Warung
- **WHEN** user mendaftar sebagai Seller dengan tipe `warung`
- **THEN** sistem hanya mewajibkan unggah dokumen KTP, tanpa meminta NIB.

#### Scenario: Registering as a Coffeeshop
- **WHEN** user mendaftar sebagai Seller dengan tipe `coffeeshop`
- **THEN** sistem mewajibkan unggah dokumen KTP dan NIB (Nomor Induk Berusaha).

### Requirement: Seat Booking System
Aplikasi MUST mengizinkan `Buyer` melakukan reservasi tempat/meja pada toko yang mendukung fitur booking (khususnya tipe `coffeeshop` atau resto).

#### Scenario: Buyer books a seat
- **WHEN** Buyer memilih jam, tanggal, dan jumlah orang pada halaman profil toko (Coffeeshop)
- **THEN** sistem membuat record Booking berstatus `PENDING` dan mengirim notifikasi reservasi ke Seller untuk dikonfirmasi.

### Requirement: Real-time Crowd Level Indicator
Sistem MUST menghitung indikator tingkat keramaian suatu tempat dan menampilkannya kepada `Buyer` agar mereka dapat mempertimbangkan sebelum datang.

#### Scenario: Crowd level is low
- **WHEN** jumlah pengunjung aktif (transaksi POS aktif + booking berjalan) berada di bawah 40% dari kapasitas maksimal toko
- **THEN** Buyer melihat indikator berwarna Hijau (Sepi) di profil toko.

#### Scenario: Crowd level is high
- **WHEN** jumlah pengunjung aktif mendekati kapasitas maksimal toko (> 80%)
- **THEN** Buyer melihat indikator berwarna Merah (Sangat Ramai) di profil toko.
