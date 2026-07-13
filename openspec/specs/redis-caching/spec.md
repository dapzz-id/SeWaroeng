## ADDED Requirements

### Requirement: Redis Cache for Product Catalog
Sistem SHALL menggunakan Redis sebagai lapisan caching untuk data katalog produk. TTL default 5 menit, dapat dikonfigurasi per-tenant.

#### Scenario: Cache hit returns fast response
- **WHEN** customer mengakses katalog produk yang sudah ada di cache
- **THEN** sistem mengembalikan data dari Redis tanpa query ke PostgreSQL (response time < 10ms)

#### Scenario: Cache miss fetches from DB and caches
- **WHEN** cache key tidak ditemukan di Redis
- **THEN** sistem query ke PostgreSQL, menyimpan result ke Redis dengan TTL, lalu mengembalikan data

### Requirement: Redis Session Management
Session Token MUST disimpan di Redis dengan struktur key: session:{user_id}:{session_uuid}, berisi metadata: device_info, ip_address, created_at, last_active_at.

#### Scenario: Session stored on login
- **WHEN** user berhasil login
- **THEN** sistem menyimpan Session Token di Redis dengan TTL 7 hari dan metadata device

#### Scenario: Session list for user
- **WHEN** user mengakses GET /api/v1/auth/sessions
- **THEN** sistem mengembalikan daftar semua sesi aktif user dengan info device dan last active

### Requirement: Redis Rate Limiting Storage
Token Bucket state (token count dan last refill timestamp) MUST disimpan di Redis menggunakan atomic Lua script untuk mencegah race condition.

#### Scenario: Atomic token consumption
- **WHEN** dua request datang bersamaan dari IP yang sama
- **THEN** Redis Lua script memastikan hanya satu yang berhasil mengurangi token, tidak ada race condition
