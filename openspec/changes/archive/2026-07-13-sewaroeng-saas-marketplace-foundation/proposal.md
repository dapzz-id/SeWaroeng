## Why

UMKM (Usaha Mikro Kecil Menengah) Indonesia membutuhkan platform digital terintegrasi yang dapat mengelola penjualan produk fisik, digital (PPOB/pulsa/top-up), serta layanan keuangan dalam satu ekosistem marketplace multi-tenant. SeWaroeng hadir sebagai solusi SaaS B2B2C yang memungkinkan setiap UMKM memiliki toko digital bertenaga enterprise dengan backend Goravel (Go) berperforma tinggi, frontend Flutter bersih, keamanan berlapis, dan dukungan multi-payment gateway tanpa harus membangun infrastruktur sendiri.

## What Changes

- Inisiasi proyek penuh dari nol: Pembangunan folder backend/ (Goravel/Go) dan frontend/ (Flutter) di D:\SeWaroeng
- Arsitektur Multi-Tenant: Shared-database shared-schema dengan isolasi data via tenant_id UUID di setiap tabel
- 3-Token Security System: Access Token (JWT), Refresh Token (HTTP-only cookie), Session Token (UUID di Redis) + Token Bucket rate limiting
- Database PostgreSQL + PostGIS: Seluruh PK/FK menggunakan UUID, B-Tree index pada kolom kritikal, GIST spatial index untuk fitur Kurir Toko
- Payment Gateway Integration: Midtrans Snap (QRIS/VA), PayPal REST, Wise Webhooks via Interface Design Pattern
- Produk Digital / PPOB Engine: Integrasi API penyedia pihak ketiga, fulfillment otomatis pasca-pembayaran
- POS/Kasir Module: Transaksi offline & pembayaran tunai/COD
- Background Worker Automation: Pemrosesan status pesanan, penerbitan resi, dan generasi PDF faktur/bukti transaksi
- Flutter Clean Architecture: Manajemen state modular, Liquid Glass + Material Design + Glassmorphism, SVG assets eksklusif
- ACID Transactions: Pemotongan stok, pemrosesan dompet, validasi pembayaran dengan Database Transactions Goravel (Commit/Rollback)
- SOLID & DRY: Pemisahan HTTP handler -> Service Layer -> Repository Layer di seluruh codebase

## Capabilities

### New Capabilities

- tenant-management: Manajemen multi-tenant UMKM; registrasi toko, konfigurasi, isolasi data via tenant_id UUID
- auth-security: Sistem autentikasi 3-token (JWT Access + Refresh HTTP-only cookie + Session UUID Redis), Token Bucket rate limiting middleware
- user-management: Manajemen pengguna per-tenant (Owner, Staff, Customer) dengan RBAC
- product-catalog: Katalog produk fisik & digital per-toko, caching Redis, pencarian & filter
- digital-product-ppob: Engine PPOB/pulsa/top-up/aset game: integrasi API penyedia, fulfillment otomatis, penyimpanan SN
- order-management: Alur pemesanan produk fisik & digital, manajemen status, ACID transactions
- shipping-courier-toko: Kalkulasi ongkir berbasis PostGIS (ST_DistanceSphere), indeks spasial GIST, Kurir Toko
- payment-gateway: Multi-gateway (Midtrans Snap QRIS/VA, PayPal REST, Wise Webhook) via Interface Pattern, webhook signature validation
- pos-cashier: Modul Kasir offline, transaksi tunai/COD, sinkronisasi ke backend
- wallet-balance: Dompet digital per-tenant/customer, top-up, debit, transfer, riwayat transaksi
- background-worker: Worker otomasi: update status pesanan, penerbitan resi, generasi PDF faktur & bukti transaksi
- redis-caching: Lapisan caching Redis untuk katalog produk, session management, rate limiting
- flutter-ui-foundation: Clean Architecture Flutter, Liquid Glass + Material + Glassmorphism, RepaintBoundary caching, SVG icon system, fallback perangkat rendah
- api-gateway-middleware: HTTP middleware stack: auth, rate limiting, CORS, request logging, error handling

### Modified Capabilities

(Tidak ada - proyek diinisiasi dari nol)

## Impact

- Backend: Folder baru D:\SeWaroeng\backend\ dengan modul Goravel
- Frontend: Folder baru D:\SeWaroeng\frontend\ dengan proyek Flutter Clean Architecture
- Database: Schema PostgreSQL dengan ekstensi PostGIS; migrasi Goravel; seluruh tabel UUID PK/FK
- Infrastruktur: Redis (session, cache, rate limiting), PostgreSQL+PostGIS, koneksi ke API eksternal
- Keamanan: Middleware JWT, HTTP-only cookie, Token Bucket, webhook signature verification
- Dependencies baru: goravel/framework, go-redis/redis, golang-jwt/jwt, google/uuid, jung-kurt/gofpdf, flutter_svg, go_router, riverpod, dio, hive
- Brand Identity: Header kode di seluruh file mencantumkan Neura Cakrawira Solusi dan nama developer
