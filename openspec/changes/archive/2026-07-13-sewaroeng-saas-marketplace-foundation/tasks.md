## 1. Project Initialization & Infrastructure Setup

- [x] 1.1 Buat folder `D:\SeWaroeng\backend\` dan inisiasi Goravel project (`go mod init github.com/neuracakrawirasolusi/sewaroeng-backend`)
- [x] 1.2 Buat folder `D:\SeWaroeng\frontend\` dan inisiasi Flutter project (`flutter create sewaroeng`)
- [x] 1.3 Setup Docker Compose untuk PostgreSQL+PostGIS dan Redis di root project
- [x] 1.4 Konfigurasi file environment `.env` untuk backend (DB_URL, REDIS_URL, JWT_SECRET, dll)
- [x] 1.5 Setup struktur folder backend: `cmd/`, `internal/modules/`, `internal/infrastructure/`, `pkg/`, `database/migrations/`
- [x] 1.6 Setup struktur folder frontend Clean Architecture: `lib/core/`, `lib/features/`, `lib/shared/`
- [x] 1.7 Tambahkan brand identity header ke semua file Go: komentar "// Neura Cakrawira Solusi - SeWaroeng" di setiap file

## 2. Database Schema & Migrations

- [x] 2.1 Buat migration: enable ekstensi PostGIS di PostgreSQL (`CREATE EXTENSION IF NOT EXISTS postgis`)
- [x] 2.2 Buat migration tabel `tenants` (UUID PK, nama, slug, status, created_at, updated_at)
- [x] 2.3 Buat migration tabel `stores` (UUID PK, tenant_id FK, nama, deskripsi, logo_url, location GEOGRAPHY(POINT,4326), jam_operasional JSONB)
- [x] 2.4 Buat migration tabel `users` (UUID PK, tenant_id FK nullable, email, password_hash, role ENUM, deleted_at)
- [x] 2.5 Buat migration tabel `user_addresses` (UUID PK, user_id FK, alamat_lengkap, location GEOGRAPHY(POINT,4326), is_default)
- [x] 2.6 Buat migration tabel `product_categories` (UUID PK, tenant_id FK, nama, parent_id FK self-referencing)
- [x] 2.7 Buat migration tabel `products` (UUID PK, tenant_id FK, category_id FK, nama, deskripsi, harga, stok, berat, tipe ENUM(fisik/digital), status)
- [x] 2.8 Buat migration tabel `orders` (UUID PK, tenant_id FK, customer_id FK, status ENUM state machine, total, payment_method, payment_status)
- [x] 2.9 Buat migration tabel `order_items` (UUID PK, order_id FK, product_id FK, qty, harga_satuan, subtotal)
- [x] 2.10 Buat migration tabel `order_documents` (UUID PK, order_id FK, tipe ENUM, file_url, created_at)
- [x] 2.11 Buat migration tabel `digital_transactions` (UUID PK, order_id FK, provider, sn_encrypted, status, fulfilled_at)
- [x] 2.12 Buat migration tabel `store_couriers` (UUID PK, tenant_id FK, nama, radius_km, rate_per_km, location GEOGRAPHY(POINT,4326))
- [x] 2.13 Buat migration tabel `payments` (UUID PK, order_id FK, gateway ENUM, external_id, status, amount, payload JSONB)
- [x] 2.14 Buat migration tabel `wallets` (UUID PK, user_id FK UNIQUE, saldo NUMERIC(15,2))
- [x] 2.15 Buat migration tabel `wallet_transactions` (UUID PK, wallet_id FK, tipe ENUM, jumlah, saldo_setelah, referensi, created_at)
- [x] 2.16 Buat migration tabel `pos_transactions` (UUID PK, tenant_id FK, staff_id FK, total, bayar, kembalian, metode, created_at)
- [x] 2.17 Buat migration tabel `pos_transaction_items` (UUID PK, pos_transaction_id FK, product_id FK, qty, harga)
- [x] 2.18 Buat migration tabel `sessions` (UUID PK, user_id FK, refresh_token_hash, device_info, ip_address, expired_at)
- [x] 2.19 Tambahkan B-Tree index: tenant_id pada semua tabel tenant-aware, email pada users, status pada orders dan payments, store_id pada products
- [x] 2.20 Tambahkan GIST index pada kolom location di tabel stores, store_couriers, user_addresses

## 3. Backend Core & Infrastructure (Goravel)

- [x] 3.1 Setup Goravel providers: DatabaseServiceProvider, RedisServiceProvider, QueueServiceProvider, ScheduleServiceProvider
- [x] 3.2 Implementasikan `pkg/response/` - package helper untuk standardized API response format (success/error JSON)
- [x] 3.3 Implementasikan `pkg/uuid/` - helper generator UUID v7 untuk semua PK/FK
- [x] 3.4 Implementasikan `internal/infrastructure/redis/` - Redis client wrapper (go-redis) dengan helper Get/Set/Del/Expire
- [x] 3.5 Implementasikan `internal/infrastructure/storage/` - S3-compatible storage client untuk upload PDF
- [x] 3.6 Setup Goravel Route groups: `/api/v1/public`, `/api/v1/auth`, `/api/v1/` (protected)

## 4. API Gateway Middleware Stack

- [x] 4.1 Implementasikan `AuthMiddleware`: validasi JWT, cek Session Token di Redis, inject claims ke context
- [x] 4.2 Implementasikan `TenantMiddleware`: ekstrak tenant_id dari JWT claim, inject ke context, cek status tenant
- [x] 4.3 Implementasikan `RoleMiddleware`: RBAC berdasarkan role dari JWT claim
- [x] 4.4 Implementasikan `RateLimitMiddleware`: Token Bucket via Redis Lua script atomic (5 req/60s per IP untuk auth endpoints)
- [x] 4.5 Implementasikan `CORSMiddleware`: konfigurasi origin, methods, headers yang diperbolehkan
- [x] 4.6 Implementasikan `LoggingMiddleware`: structured JSON log per request (method, path, tenant_id, latency, status)
- [x] 4.7 Implementasikan `RecoveryMiddleware`: recover panic, log stack trace, return 500 JSON response

## 5. Authentication & Security Module

- [x] 5.1 Buat `internal/modules/auth/` dengan struktur: handler, service, repository
- [x] 5.2 Implementasikan `AuthRepository`: CRUD sessions di DB + Redis session operations
- [x] 5.3 Implementasikan `AuthService`: login (generate 3 tokens), logout, refresh token, force logout session tertentu
- [x] 5.4 Implementasikan `AuthHandler`: POST /auth/login, POST /auth/register, POST /auth/refresh, DELETE /auth/logout, GET /auth/sessions, DELETE /auth/sessions/{id}
- [x] 5.5 Implementasikan bcrypt password hashing dengan cost factor 12
- [x] 5.6 Implementasikan JWT Access Token generator (HS256, 15 menit TTL, claims: user_id, tenant_id, role, session_id)
- [x] 5.7 Implementasikan Refresh Token: generate UUID, simpan hash di DB, set HTTP-only cookie
- [x] 5.8 Implementasikan Session Token: generate UUID, simpan di Redis dengan metadata device, TTL 7 hari
- [x] 5.9 Implementasikan Token Bucket Lua script di Redis untuk rate limiting endpoints auth

## 6. Tenant & Store Management Module

- [x] 6.1 Buat `internal/modules/tenant/` dengan struktur: handler, service, repository
- [x] 6.2 Implementasikan `TenantRepository`: CRUD tenants + stores dengan scope tenant_id
- [x] 6.3 Implementasikan `TenantService`: registrasi tenant baru, update profil toko, manajemen status
- [x] 6.4 Implementasikan `TenantHandler`: POST /tenants, GET /tenants/{id}, PUT /store/profile
- [x] 6.5 Validasi unique email tenant saat registrasi

## 7. User Management Module

- [x] 7.1 Buat `internal/modules/user/` dengan struktur: handler, service, repository
- [x] 7.2 Implementasikan `UserRepository`: CRUD users dengan soft delete support (deleted_at)
- [x] 7.3 Implementasikan `UserService`: CRUD staff per tenant, CRUD customer addresses, role management
- [x] 7.4 Implementasikan `UserHandler`: POST /users/staff, GET /users, DELETE /users/{id}, CRUD /customers/addresses
- [x] 7.5 Validasi unique email per-role (customer global, staff per-tenant)

## 8. Product Catalog Module

- [x] 8.1 Buat `internal/modules/product/` dengan struktur: handler, service, repository
- [x] 8.2 Implementasikan `ProductRepository`: CRUD products dengan tenant scope, stock SELECT FOR UPDATE
- [x] 8.3 Implementasikan `ProductService`: CRUD produk, search/filter, stock reservation, cache invalidation
- [x] 8.4 Implementasikan `ProductHandler`: CRUD /products, GET /stores/{id}/products (public), GET /products?q=...
- [x] 8.5 Implementasikan Redis caching di ProductService: cache key `catalog:{store_id}:{page}`, TTL 5 menit
- [x] 8.6 Implementasikan kategori produk dengan self-referencing hierarchy

## 9. Order Management Module

- [x] 9.1 Buat `internal/modules/order/` dengan struktur: handler, service, repository
- [x] 9.2 Implementasikan `OrderRepository`: CRUD orders dengan tenant scope dan state machine validation
- [x] 9.3 Implementasikan `OrderService`: create order (DB Transaction: reserve stok + buat order + inisiasi payment), cancel order, update status
- [x] 9.4 Implementasikan `OrderHandler`: POST /orders, GET /orders, GET /orders/{id}, POST /orders/{id}/cancel, PUT /orders/{id}/status
- [x] 9.5 Implementasikan state machine transitions dengan validasi transisi ilegal
- [x] 9.6 Implementasikan ACID transaction: BEGIN - reserve stok (SELECT FOR UPDATE) - insert order - insert order_items - COMMIT/ROLLBACK

## 10. Payment Gateway Module

- [x] 10.1 Buat `pkg/payment/contracts/gateway.go`: definisi interface PaymentGatewayProvider
- [x] 10.2 Implementasikan `pkg/payment/midtrans/`: MidtransProvider (CreatePayment, VerifyWebhook, GetStatus)
- [x] 10.3 Implementasikan `pkg/payment/paypal/`: PayPalProvider (CreateOrder, CapturePayment, VerifyWebhook)
- [x] 10.4 Implementasikan `pkg/payment/wise/`: WiseProvider (VerifyWebhook dengan RSA-SHA256 signature)
- [x] 10.5 Buat `internal/modules/payment/` gateway: handler, service, repository
- [x] 10.6 Implementasikan webhook handler: POST /webhooks/midtrans, POST /webhooks/paypal, POST /webhooks/wise
- [x] 10.7 Implementasikan validasi webhook signature untuk setiap gateway (Midtrans: SHA512, PayPal: HMAC-SHA256, Wise: RSA-SHA256)
- [x] 10.8 Implementasikan gateway registry: PaymentGatewayRegistry yang mengembalikan implementasi berdasarkan nama gateway
- [x] 10.9 Implementasikan COD payment handler: POST /orders/{id}/confirm-cod (Staff only)

## 11. Digital Product / PPOB Module

- [x] 11.1 Buat `pkg/digital/contracts/provider.go`: definisi interface DigitalProductProvider
- [x] 11.2 Implementasikan stub provider (untuk testing awal) yang simulate respons PPOB
- [x] 11.3 Buat `internal/modules/digital/`: handler, service, repository
- [x] 11.4 Implementasikan `DigitalService`: inquiry produk digital, proses fulfillment (panggil provider API)
- [x] 11.5 Implementasikan enkripsi SN menggunakan AES-256-GCM sebelum disimpan ke DB
- [x] 11.6 Implementasikan `DigitalHandler`: POST /digital/inquiry, GET /orders/{id}/digital-result
- [x] 11.7 Implementasikan retry logic di Queue job untuk fulfillment gagal (max 3x, exponential backoff)
- [x] 11.8 Implementasikan auto-refund ke wallet jika fulfillment gagal setelah max retry

## 12. Shipping / Kurir Toko Module

- [x] 12.1 Buat `internal/modules/shipping/`: handler, service
- [x] 12.2 Implementasikan `ShippingHandler`: POST /shipping/calculate
- [x] 12.3 Implementasikan `ShippingService`: hitung ongkir berdasarkan jarak real-world. Gunakan ST_DistanceSphere PostGIS.
- [x] 12.4 Validasi kurir: hanya tampilkan kurir yang aktif dan jarak antara toko & pembeli <= radius_km kurirers, POST /shipping/calculate
- [x] 12.5 Verifikasi GIST index pada kolom location saat migration dijalankan

## 13. Wallet Module

- [x] 13.1 Buat `internal/modules/wallet/`: handler, service, repository
- [x] 13.2 Implementasikan `WalletRepository`: CRUD wallet dan wallet_transactions
- [x] 13.3 Implementasikan `WalletService`: credit, debit dengan SELECT FOR UPDATE untuk cegah race condition
- [x] 13.4 Implementasikan `WalletHandler`: GET /wallet, GET /wallet/transactions, POST /wallet/topupransactions
- [x] 13.5 Implementasikan auto-create wallet saat user baru terdaftar

## 14. POS / Kasir Module

- [x] 14.1 Buat `internal/modules/pos/`: handler, service, repository
- [x] 14.2 Implementasikan `POSRepository`: CRUD pos_transactions dan items
- [x] 14.3 Implementasikan `POSService`: create transaction (hitung total, bayar, kembalian, kurangi stok real-time)
- [x] 14.4 Implementasikan offline sync logic: POST /pos/sync menerima array transaksi dari Flutter (Hive)
- [x] 14.5 Implementasikan `POSHandler`: POST /pos/transactions, GET /pos/transactions, POST /pos/sync

## 15. Background Worker Module

- [x] 15.1 Buat folder `app/jobs/` dan `app/console/`
- [x] 15.2 Implementasikan job `DigitalFulfillmentJob`: execute API PPOB dan simpan SN terenkripsi
- [x] 15.3 Implementasikan job `GenerateInvoicePDFJob`: generate PDF invoice menggunakan go-pdf/fpdf, upload ke storage, simpan URL
- [x] 15.4 Implementasikan job `SendEmailNotificationJob`: kirim email transaksional via SMTP (order confirm, shipping update, digital SN)
- [x] 15.5 Implementasikan job `TrackingNumberNotificationJob`: kirim notifikasi ke customer saat resi diisi
- [x] 15.6 Implementasikan Schedule task `AutoCompleteDeliveredOrders`: berjalan setiap jam, auto-complete order DELIVERED > 3 hari
- [x] 15.7 Implementasikan PDF template: faktur penjualan (fisik) dan bukti transaksi (digital) dengan logo SeWaroeng
- [x] 15.8 Implementasikan failed job handling dan dead-letter queue monitoring

## 16. Flutter Frontend Foundation

- [x] 16.1 Tambahkan dependencies ke pubspec.yaml: flutter_svg, go_router, riverpod (flutter_riverpod, riverpod_annotation), dio, hive_flutter, google_fonts, shared_preferences
- [x] 16.2 Implementasikan `core/theme/app_theme.dart`: ThemeData Material 3 dengan warna brand SeWaroeng (warm orange-amber-cream palette)
- [x] 16.3 Implementasikan `core/theme/app_colors.dart`: palet warna brand SeWaroeng
- [x] 16.4 Implementasikan `core/theme/app_typography.dart`: TextTheme dengan Google Fonts (Poppins + Inter)
- [x] 16.5 Implementasikan `shared/widgets/glass_container.dart`: GlassContainer dengan RepaintBoundary + BackdropFilter + fallback
- [x] 16.6 Implementasikan `core/services/device_capability_service.dart`: deteksi RAM dan GPU tier untuk menentukan apakah glass effects diaktifkan
- [x] 16.7 Implementasikan `shared/widgets/svg_icon.dart`: SvgIcon widget reusable dengan support color parameter
- [x] 16.8 Tambahkan minimal 20 SVG icon assets di `assets/icons/` (home, cart, orders, profile, settings, dll)
- [x] 16.9 Implementasikan `core/router/app_router.dart`: go_router setup dengan shell route dan protected route guard
- [x] 16.10 Implementasikan `core/network/api_client.dart`: Dio client dengan base URL, interceptors (auth header, error handling, token refresh)

## 17. Flutter Feature Modules

- [x] 17.1 Implementasikan feature `auth/`: domain (AuthEntity, LoginUseCase), data (AuthRemoteDataSource, AuthRepositoryImpl), presentation (LoginPage, LoginNotifier)
- [x] 17.2 Implementasikan feature `home/`: store catalog listing, search bar, kategori chips
- [x] 17.3 Implementasikan feature `product/`: product detail page, add to cart, stock indicator
- [x] 17.4 Implementasikan feature `cart/`: cart management, item qty control, subtotal calculation
- [x] 17.5 Implementasikan feature `checkout/`: shipping method selection, payment method selection, order summary
- [x] 17.6 Implementasikan feature `orders/`: order list, order detail, order tracking
- [x] 17.7 Implementasikan feature `wallet/`: saldo display, top-up flow, transaction history
- [x] 17.8 Implementasikan feature `pos/`: POS interface untuk Staff (item selection, bayar, print receipt, offline sync via Hive)
- [x] 17.9 Implementasikan feature `store-management/`: kelola produk, stok, kurir toko, profil toko
- [x] 17.10 Implementasikan feature `profile/`: profil user, daftar alamat, manajemen sesi aktif (dengan tombol force logout per device)

## 18. Testing & Quality Assurance

- [x] 18.1 Tulis unit test untuk semua Service layer (Go): auth, product, order, payment, wallet, shipping
- [x] 18.2 Tulis integration test untuk DB Transaction scenarios: stok, wallet debit, order creation
- [x] 18.3 Tulis test untuk middleware: AuthMiddleware, TenantMiddleware, RateLimitMiddleware
- [x] 18.4 Tulis test untuk payment gateway webhook signature validation (Midtrans, PayPal, Wise)
- [x] 18.5 Tulis test untuk multi-tenant data isolation (cross-tenant access blocked)
- [x] 18.6 Tulis widget test Flutter untuk GlassContainer (normal + fallback mode)
- [x] 18.7 Tulis unit test Flutter untuk Use Cases menggunakan mock repositories

## 19. Documentation & Developer Experience

- [x] 19.1 Buat `README.md` di root `D:\SeWaroeng\` dengan overview arsitektur, setup instructions, dan brand identity
- [x] 19.2 Buat `backend/README.md`: instruksi setup, env vars, jalankan migration, menjalankan server dan queue worker
- [x] 19.3 Buat `frontend/README.md`: instruksi setup Flutter, menjalankan app, build untuk berbagai platform
- [x] 19.4 Buat `docs/api/` dengan dokumentasi API endpoint (format OpenAPI/Swagger atau Markdown)
- [x] 19.5 Buat `docs/database/schema.md`: ERD dan penjelasan relasi antar tabel
- [x] 19.6 Pastikan seluruh file source code memiliki brand header: "// Neura Cakrawira Solusi | SeWaroeng | Developer: [Nama]"
