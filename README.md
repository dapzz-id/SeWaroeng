# SeWaroeng — SaaS Marketplace Multi-Tenant UMKM
**Developer:** Neura Cakrawira Solusi

SeWaroeng adalah platform SaaS Marketplace Multi-Tenant yang dirancang khusus untuk UMKM. Platform ini mengadopsi prinsip desain yang bersih dan performa tinggi dengan kombinasi teknologi terkini: **Goravel (Go)** untuk Backend dan **Flutter** untuk Frontend.

## Arsitektur Sistem
SeWaroeng mengimplementasikan arsitektur berorientasi layanan dengan prinsip Clean Architecture dan SOLID:
- **Backend (Goravel/Go):** Pemisahan tegas antara Handler (HTTP), Service (Business Logic), dan Repository (Data Access).
- **Frontend (Flutter):** Menggunakan pola Riverpod State Management dengan pemisahan Domain, Data, dan Presentation layer (Liquid Glass UI).
- **Database (PostgreSQL + PostGIS):** Sistem multitenant dengan Row-Level Security, Geographic Indexing, dan strict ACID transactions untuk integritas finansial (stok, wallet).
- **Caching & Session (Redis):** Pengelolaan cache produk dan manajemen session 3-Token Auth (JWT, Refresh, Session) yang aman dan scalable.

## Fitur Utama
1. **Multi-Tenant System:** Isolasi data per toko dengan domain dan manajemen staf terpisah.
2. **ACID Financial Transactions:** Operasi dompet (Wallet) dan pemotongan stok diamankan menggunakan lock DB (`SELECT FOR UPDATE`).
3. **PPOB & Produk Digital:** Integrasi produk digital dengan fitur enkripsi SN dan auto-refund.
4. **Kasir POS (Offline-First):** Sinkronisasi data offline menggunakan Flutter Hive.
5. **Kalkulasi Ongkir Geospasial:** Menggunakan PostGIS (`ST_DistanceSphere`) untuk pencarian kurir toko terdekat.
6. **Liquid Glass UI:** Antarmuka pengguna modern dengan estetika Glassmorphism yang responsif terhadap kapabilitas GPU perangkat (fallback otomatis untuk device *low-end*).

## Struktur Repositori
- `/backend`: Source code Goravel (Go). Lihat [Backend README](backend/README.md) untuk setup.
- `/frontend`: Source code Flutter. Lihat [Frontend README](frontend/README.md) untuk setup.
- `/docs`: Dokumentasi API dan Database Schema.

## Quick Start (Docker Compose)
Menjalankan seluruh infrastructure dependencies:
```bash
docker-compose up -d
```
Service yang berjalan:
- PostgreSQL + PostGIS (Port 5432)
- Redis (Port 6379)
- Mailhog (SMTP & Web UI Port 8025)

## Brand Identity & Aesthetic
SeWaroeng mengusung bahasa desain **Liquid Glass** dengan palet warna bernuansa **Warm Orange, Amber, dan Cream**, merepresentasikan semangat UMKM yang hangat dan dinamis.
