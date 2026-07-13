## ADDED Requirements

### Requirement: Authentication Middleware
Setiap request ke protected endpoint MUST melalui middleware AuthMiddleware yang memvalidasi JWT Access Token dari header Authorization: Bearer {token}.

#### Scenario: Valid token passes middleware
- **WHEN** request membawa JWT valid (tidak expire, signature valid, session aktif di Redis)
- **THEN** middleware mengekstrak claims (user_id, tenant_id, role) dan menyimpan ke request context; request diteruskan ke handler

#### Scenario: Missing or invalid token rejected
- **WHEN** request ke protected endpoint tanpa Authorization header atau dengan JWT tidak valid
- **THEN** middleware mengembalikan 401 Unauthorized sebelum request mencapai handler

### Requirement: CORS Configuration
Backend MUST mengkonfigurasi CORS middleware yang memperbolehkan request dari domain frontend Flutter Web dan aplikasi mobile.

#### Scenario: CORS preflight handled
- **WHEN** browser mengirimkan OPTIONS preflight request
- **THEN** server merespons dengan headers Access-Control-Allow-Origin, Access-Control-Allow-Methods, Access-Control-Allow-Headers yang sesuai konfigurasi

### Requirement: Request Logging Middleware
Setiap request yang masuk ke backend MUST dicatat (log) dengan informasi: method, path, tenant_id, user_id, latency, dan response status code.

#### Scenario: Request logged with context
- **WHEN** request selesai diproses
- **THEN** sistem mencatat log entry terstruktur (JSON) dengan semua field yang diperlukan

### Requirement: Global Error Handling
Backend MUST memiliki middleware global error handler yang menangkap semua panic dan error yang tidak tertangani, mengembalikan response JSON yang konsisten.

#### Scenario: Unhandled panic recovered
- **WHEN** terjadi panic di handler atau service
- **THEN** middleware recover dari panic, mencatat error log lengkap dengan stack trace, mengembalikan 500 Internal Server Error dengan format JSON standar tanpa expose internal detail

### Requirement: Standardized API Response Format
Seluruh response API MUST menggunakan format JSON yang konsisten:
- Success: { "success": true, "data": {...}, "meta": {...} }
- Error: { "success": false, "error": { "code": "...", "message": "..." } }

#### Scenario: Success response format
- **WHEN** handler berhasil memproses request
- **THEN** response memiliki HTTP status 2xx dan body JSON dengan field success: true dan data

#### Scenario: Error response format
- **WHEN** handler mengembalikan error
- **THEN** response memiliki HTTP status 4xx/5xx dan body JSON dengan field success: false dan error object
