# SeWaroeng Backend (Goravel)

Backend SeWaroeng dibangun menggunakan framework [Goravel](https://goravel.dev), mengedepankan performa Go dengan struktur MVC yang solid.

## Requirements
- Go >= 1.20
- PostgreSQL >= 15 dengan ekstensi PostGIS dan UUID-OSSP
- Redis >= 7.0

## Setup Instruksi

1. **Konfigurasi Environment**
   Salin `.env.example` ke `.env`:
   ```bash
   cp .env.example .env
   ```
   Pastikan konfigurasi DB (`DB_HOST`, `DB_PORT`, dsb) dan Redis sesuai.

2. **Install Dependencies**
   ```bash
   go mod tidy
   ```

3. **Migrasi Database**
   Jalankan migrasi untuk membuat tabel, index, dan ekstensi:
   ```bash
   go run . artisan migrate
   ```

4. **Menjalankan Server (Development)**
   ```bash
   go run .
   ```
   Server akan berjalan di `http://localhost:3000`.

5. **Menjalankan Queue Worker**
   Untuk memproses background jobs (Digital PPOB, Notifikasi Email, PDF Invoices):
   ```bash
   go run . artisan queue:work
   ```

## Struktur Direktori
- `app/`: Console commands, Exceptions, Jobs (Background workers).
- `internal/`: Logic utama aplikasi.
  - `middleware/`: Auth, Tenant, Role, RateLimiter.
  - `modules/`: Feature modules (auth, order, product, shipping, wallet, pos).
    - Masing-masing modul memiliki struktur: `handler`, `service`, `repository`.
- `pkg/`: Utility functions dan abstraksi external (crypto, jwt, payment gateway, response formatter, uuid).
- `routes/`: Registrasi API endpoints.
- `database/migrations/`: File SQL untuk DDL database.
