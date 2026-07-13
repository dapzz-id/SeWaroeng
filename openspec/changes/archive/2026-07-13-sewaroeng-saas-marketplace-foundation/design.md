## Context

SeWaroeng adalah platform SaaS Marketplace Multi-Tenant yang dibangun dari nol oleh **Neura Cakrawira Solusi** untuk melayani ekosistem UMKM Indonesia. Tidak ada codebase sebelumnya. Backend menggunakan **Goravel** (Go framework MVC mirip Laravel), frontend menggunakan **Flutter** dengan Clean Architecture. Sistem harus mendukung ribuan tenant UMKM secara bersamaan dengan isolasi data yang ketat, performa tinggi, dan keamanan enterprise-grade.

Batasan teknis:
- Seluruh PK/FK menggunakan UUID (bukan integer auto-increment)
- Shared-database shared-schema dengan kolom `tenant_id` sebagai diskriminator
- Semua efek visual glass (BackdropFilter) harus di-cache via RepaintBoundary
- Seluruh ikon harus SVG via custom reusable widget

## Goals / Non-Goals

**Goals:**
- Membangun fondasi arsitektur backend Goravel dengan struktur SOLID: Handler → Service → Repository
- Membangun fondasi arsitektur frontend Flutter Clean Architecture dengan state management modular
- Mengimplementasikan sistem keamanan 3-token (JWT + Refresh Cookie + Session Redis)
- Membangun skema database PostgreSQL+PostGIS dengan UUID di seluruh tabel dan indexing optimal
- Mengimplementasikan multi-payment gateway via Interface Pattern
- Membangun engine PPOB/digital product dengan fulfillment otomatis
- Mengimplementasikan POS/Kasir untuk transaksi offline
- Membangun background worker untuk otomasi bisnis (PDF, resi, status)
- Mengimplementasikan Token Bucket rate limiting berbasis Redis
- Menerapkan ACID transactions pada operasi krusial (stok, dompet, pembayaran)
- Membangun fitur Kurir Toko berbasis PostGIS geolocation

**Non-Goals:**
- Mobile app native iOS/Android yang terpisah dari Flutter (Flutter kompile ke semua platform)
- Integrasi marketplace eksternal (Tokopedia, Shopee) di fase ini
- Admin panel super-admin berbasis web (dikelola via backend API)
- Machine learning / AI recommendation engine di fase ini
- Multi-currency selain IDR + USD (PayPal) + GBP (Wise)

## Decisions

### D1: Goravel sebagai Backend Framework

**Pilihan**: Goravel (Go) vs Gin/Echo (bare framework) vs Fiber

**Keputusan**: Goravel — menyediakan MVC/Laravel-like structure dengan ORM (Eloquent-style), Queue, Schedule, Facades, tanpa harus membangun scaffolding dari nol.

**Rasional**: Goravel memberikan produktivitas tinggi (convention over configuration), built-in Queue untuk background worker, built-in Schedule untuk cron, built-in Artisan-like console commands. Sangat cocok untuk tim yang familiar dengan pola Laravel namun membutuhkan performa Go.

**Alternatif yang dipertimbangkan**:
- Gin + custom layers: terlalu banyak boilerplate untuk multi-module
- Fiber: lebih cepat tapi ekosistem lebih tipis, kurang mature untuk enterprise

---

### D2: Shared-Database Shared-Schema Multi-Tenancy

**Pilihan**: Separate DB per tenant vs Separate Schema per tenant vs Shared Schema + tenant_id

**Keputusan**: Shared-database, shared-schema dengan kolom `tenant_id UUID NOT NULL` di setiap tabel yang bersifat tenant-aware.

**Rasional**: 
- Lebih cost-effective untuk ratusan/ribuan UMKM (tidak perlu provision DB per tenant)
- Goravel migration lebih sederhana (satu set migration)
- Query isolation dilakukan via middleware yang secara otomatis menambahkan WHERE tenant_id = ? pada setiap query Repository
- B-Tree index pada tenant_id memastikan performa query tetap optimal

**Alternatif yang dipertimbangkan**:
- Separate schema: PostgreSQL mendukung, tapi migration complexity tinggi
- Separate DB: isolasi maksimal, tapi sangat costly untuk UMKM scale

---

### D3: 3-Token Authentication System

**Pilihan**: Single JWT vs 2-Token (Access+Refresh) vs 3-Token (Access+Refresh+Session)

**Keputusan**: 3-Token system:
1. **Access Token**: JWT stateless, short-lived (15 menit), dikirim via Authorization header
2. **Refresh Token**: Opaque token, long-lived (7 hari), disimpan sebagai HTTP-only cookie
3. **Session Token**: UUID, disimpan di Redis dengan TTL, melacak sesi aktif per device

**Rasional**: Session Token memungkinkan force logout instan (hapus dari Redis = sesi invalid langsung) tanpa menunggu JWT expire. Ini kritis untuk keamanan enterprise (deteksi perangkat tidak dikenal, revoke akses staff yang dipecat).

**Alternatif yang dipertimbangkan**:
- Single JWT: tidak bisa di-revoke
- 2-Token tanpa session: tidak bisa force logout instan

---

### D4: Token Bucket Rate Limiting via Redis

**Pilihan**: Fixed Window vs Sliding Window vs Token Bucket vs Leaky Bucket

**Keputusan**: Token Bucket algoritma via Redis (atomic Lua script atau Redis module).

**Rasional**: Token Bucket lebih toleran terhadap burst traffic (normal untuk marketplace dengan flash sale) dibanding Fixed Window yang terlalu rigid. Implementasi via Redis memastikan rate limit konsisten di seluruh instance backend yang berjalan paralel (horizontal scaling).

---

### D5: Interface Design Pattern untuk Payment Gateway

**Pilihan**: Direct API call per gateway vs Abstract Interface vs Event-driven

**Keputusan**: Go Interface (`PaymentGatewayProvider`) dengan implementasi terpisah per gateway.

**Rasional**: SOLID - Open/Closed Principle. Menambah gateway baru = implement interface baru, tidak menyentuh kode yang ada. Dependency Injection memudahkan unit testing dengan mock.

```go
// pkg/payment/contracts/gateway.go
type PaymentGatewayProvider interface {
    CreatePayment(ctx context.Context, req *CreatePaymentRequest) (*PaymentResponse, error)
    VerifyWebhook(payload []byte, signature string) (*WebhookEvent, error)
    GetPaymentStatus(ctx context.Context, externalID string) (*PaymentStatus, error)
}
```

---

### D6: Flutter Clean Architecture + State Management

**Pilihan**: GetX vs BLoC vs Riverpod vs Provider

**Keputusan**: **Riverpod** (StateNotifier + AsyncNotifier) sebagai state management, **go_router** untuk routing, **dio** untuk HTTP client.

**Rasional**: Riverpod memberikan compile-time safety, tidak bergantung pada BuildContext, sangat testable, dan mendukung modular feature-based state. Cocok dengan Clean Architecture (Presentation → Domain → Data).

**Layer Flutter**:
- **Presentation**: Widgets, Pages, StateNotifiers (Riverpod)
- **Domain**: Entities, Use Cases, Repository Contracts (abstract)
- **Data**: Repository Implementations, Data Sources (remote/local), DTOs

---

### D7: PostGIS untuk Kurir Toko

**Pilihan**: Haversine formula di Go vs PostGIS extension

**Keputusan**: PostGIS dengan tipe `GEOGRAPHY(POINT, 4326)` dan fungsi `ST_DistanceSphere`.

**Rasional**: Kalkulasi jarak langsung di database lebih efisien daripada menarik semua koordinat ke aplikasi lalu menghitung. GIST index pada kolom geography memungkinkan spatial query yang sangat cepat. PostGIS juga mendukung future features seperti radius search dan delivery zone polygon.

---

### D8: Background Worker dengan Goravel Queue

**Pilihan**: Go goroutine raw vs Cron jobs external vs Goravel Queue + Schedule

**Keputusan**: Goravel Queue (dengan Redis driver) untuk background jobs + Goravel Schedule untuk periodic tasks.

**Rasional**: Goravel Queue memberikan retry mechanism, failed job tracking, job prioritization. Schedule memberikan cron-like syntax yang familiar. Keduanya terintegrasi dengan ekosistem Goravel.

---

### D9: PDF Generation

**Pilihan**: gofpdf vs chromedp (headless Chrome) vs gopdf vs wkhtmltopdf

**Keputusan**: **go-pdf/fpdf** (fork gofpdf yang aktif maintained) untuk generasi PDF faktur dan bukti transaksi.

**Rasional**: Pure Go, tidak butuh external browser/binary, sangat ringan untuk dijalankan di background worker. Untuk kebutuhan PDF sederhana (faktur, receipt) ini lebih dari cukup.

---

### D10: Glass UI Performance Strategy

**Pilihan**: Selalu render BackdropFilter vs Conditional rendering vs RepaintBoundary + Fallback

**Keputusan**: Semua efek glass dibungkus dalam widget `GlassContainer` yang:
1. Menggunakan `RepaintBoundary` untuk isolasi layer render
2. Memeriksa device capability via `DeviceCapabilityService` (RAM < 3GB atau GPU tier rendah = fallback)
3. Fallback: solid color semi-transparent tanpa BackdropFilter

**Rasional**: BackdropFilter adalah salah satu operasi Flutter paling mahal. Pada perangkat entry-level (banyak digunakan UMKM), efek ini dapat menyebabkan frame drops signifikan. Fallback strategy memastikan UX tetap baik di semua device.

## Risks / Trade-offs

- **UUID sebagai PK**: UUID v4 random menyebabkan B-Tree index fragmentation pada insert volume tinggi → Mitigasi: gunakan UUID v7 (time-ordered) atau ULID untuk semua PK
- **Shared Schema Multi-tenancy**: Bug pada middleware tenant isolation bisa menyebabkan data leak antar tenant → Mitigasi: unit test wajib untuk setiap repository method, integration test dengan multi-tenant scenario
- **Goravel maturity**: Goravel lebih muda dibanding Gin/Echo, mungkin ada edge case yang belum didokumentasikan → Mitigasi: maintain fork lokal, backup plan dengan Gin jika ada blocker kritis
- **PostGIS dependency**: Membutuhkan PostgreSQL dengan ekstensi PostGIS terinstall → Mitigasi: Docker compose dengan image postgres+postgis, dokumentasi setup jelas
- **3rd party PPOB API**: Ketersediaan dan SLA dari penyedia PPOB tidak bisa dijamin → Mitigasi: circuit breaker pattern, retry dengan exponential backoff, status page monitoring
- **Flutter glass effects**: BackdropFilter heavy di low-end devices → Mitigasi: DeviceCapabilityService + fallback solid glass
- **PDF di background worker**: Memory spike saat generate banyak PDF bersamaan → Mitigasi: queue concurrency limit, streaming generation

## Migration Plan

1. Provision PostgreSQL instance dengan ekstensi PostGIS
2. Provision Redis instance
3. Jalankan `go mod init` dan setup Goravel project di `backend/`
4. Jalankan Goravel migration untuk create all tables
5. Seed data master (kategori produk, konfigurasi payment gateway)
6. Deploy backend service
7. Jalankan Flutter project setup di `frontend/`
8. Connect frontend ke backend API

**Rollback**: Karena proyek baru (greenfield), rollback = revert ke commit sebelumnya.

## Open Questions

- Nama developer/founder untuk header kode (diperlukan untuk brand identity di setiap file header)
- Versi minimum Flutter yang ditarget (Flutter 3.x stable atau 3.22+?)
- Apakah PPOB provider sudah ditentukan (contoh: Digiflazz, iReap, VPayment)?
- Apakah ada ketentuan branding warna khusus untuk SeWaroeng selain yang akan didefinisikan?
