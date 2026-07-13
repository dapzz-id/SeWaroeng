## 1. Backend Cleanup — Hapus Folder Kosong

- [x] 1.1 Identifikasi semua folder kosong di `backend/` (termasuk `app/console`, `cmd/api`, `internal/infrastructure/database`, `internal/infrastructure/storage`, `internal/modules/*/dto`, `pkg/mailer`, `pkg/pdf`, dll)
- [x] 1.2 Hapus semua folder kosong yang tidak memiliki file dan tidak diperlukan oleh framework
- [x] 1.3 Verifikasi bahwa tidak ada file source code yang terhapus secara tidak sengaja

## 2. Database Seeders

- [x] Create Product seeder with realistic test data for `Toko Demo SeWaroeng`.
- [x] Create Store seeder linked to `Toko Demo SeWaroeng`.
- [x] Connect `DatabaseSeeder` to the main execution flow (`bootstrap/app.go` / `facades.Seeder().Register()`).

### 3. Verification & Execution
- [x] Update database connection configuration in `.env` and `config/app.go` to work with Goravel v1.18 structure.
- [x] Ensure Docker containers (`sewaroeng_postgres`, `sewaroeng_redis`, `sewaroeng_mailhog`) are running.
- [x] Run database migrations.
- [x] Run `artisan db:seed` to populate real seed data.
- [x] Ensure the app builds successfully.
