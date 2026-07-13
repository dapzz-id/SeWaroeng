## ADDED Requirements

### Requirement: 3-Token Authentication System
Sistem SHALL mengimplementasikan autentikasi menggunakan tiga token:
1. Access Token: JWT HS256/RS256, TTL 15 menit, dikirim via Authorization: Bearer header
2. Refresh Token: Opaque UUID, TTL 7 hari, disimpan di HTTP-only Secure SameSite=Strict cookie
3. Session Token: UUID v4, disimpan di Redis dengan TTL sesuai Refresh Token, melacak sesi aktif per perangkat

#### Scenario: User login success - 3 tokens issued
- **WHEN** user mengirimkan POST /api/v1/auth/login dengan email dan password valid
- **THEN** sistem mengembalikan Access Token di response body, Refresh Token via Set-Cookie header, dan menyimpan Session Token di Redis dengan key `session:{session_id}` berisi metadata device

#### Scenario: Access Token refresh
- **WHEN** client mengirimkan POST /api/v1/auth/refresh dengan Refresh Token cookie yang valid
- **THEN** sistem memvalidasi Refresh Token, memeriksa Session Token di Redis masih aktif, lalu menerbitkan Access Token baru (Refresh Token di-rotate)

#### Scenario: Force logout via Session Token revocation
- **WHEN** admin atau user meminta force logout sesi tertentu
- **THEN** sistem menghapus Session Token dari Redis; request berikutnya dengan token lama langsung ditolak 401 meskipun JWT belum expire

### Requirement: Token Bucket Rate Limiting
Sistem SHALL mengimplementasikan Token Bucket rate limiting via Redis untuk mencegah brute-force. Setiap endpoint sensitif (login, register, forgot-password) MUST memiliki limit: 5 request per 60 detik per IP.

#### Scenario: Rate limit exceeded on login
- **WHEN** IP mengirimkan lebih dari 5 request POST /api/v1/auth/login dalam 60 detik
- **THEN** sistem mengembalikan 429 Too Many Requests dengan header Retry-After

#### Scenario: Token bucket refill
- **WHEN** 60 detik telah berlalu sejak request pertama
- **THEN** bucket ter-refill dan IP dapat melakukan request kembali

### Requirement: JWT Claim Validation
Setiap request ke protected endpoint MUST melalui middleware yang memvalidasi JWT: signature, expiry, issuer, dan audience. Session Token MUST diverifikasi keberadaannya di Redis.

#### Scenario: Expired JWT rejected
- **WHEN** client mengirimkan Access Token yang sudah expire
- **THEN** sistem mengembalikan 401 Unauthorized dengan error code TOKEN_EXPIRED

#### Scenario: Tampered JWT rejected  
- **WHEN** client mengirimkan JWT dengan signature tidak valid
- **THEN** sistem mengembalikan 401 Unauthorized dengan error code TOKEN_INVALID

### Requirement: Password Security
Password user MUST di-hash menggunakan bcrypt dengan cost factor minimum 12 sebelum disimpan ke database.

#### Scenario: Password stored securely
- **WHEN** user baru mendaftar atau mengubah password
- **THEN** sistem menyimpan bcrypt hash (bukan plaintext) di kolom password_hash

#### Scenario: Password verification
- **WHEN** user login dengan password
- **THEN** sistem menggunakan bcrypt.CompareHashAndPassword untuk verifikasi (timing-safe)
