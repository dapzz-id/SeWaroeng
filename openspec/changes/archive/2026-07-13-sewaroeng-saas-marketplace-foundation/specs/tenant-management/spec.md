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
