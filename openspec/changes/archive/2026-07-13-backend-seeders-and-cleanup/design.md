## Context

Sistem backend (Goravel) membutuhkan data awal berupa users, tenants, dan roles agar developer dapat langsung melakukan testing dan verifikasi fitur setelah setup proyek, tanpa perlu membuat data manual di database. Selain itu, direktori `backend` mungkin memiliki sisa-sisa folder kosong hasil generate atau refactoring sebelumnya yang perlu dihapus agar rapi.

## Goals / Non-Goals

**Goals:**
- Membuat seeder database untuk role, user, tenant, product (jika memungkinkan) di backend Goravel.
- Membersihkan (delete) folder kosong di dalam folder `backend/`.

**Non-Goals:**
- Mengubah struktur database / schema.
- Mengubah routing atau handler API.

## Decisions

- **Seeder implementation**: Kita akan menggunakan fitur artisan `db:seed` bawaan dari Goravel. Kita akan membuat seeder class untuk `RoleSeeder`, `TenantSeeder`, dan `UserSeeder`.
- **Cleanup method**: Menggunakan shell script atau powershell command untuk mencari direktori dengan jumlah file/item 0 (kosong) dan menghapusnya dari `/backend`.

## Risks / Trade-offs

- **Risk**: Penghapusan folder mungkin tanpa sengaja menghapus direktori yang diperlukan git (seperti `.gitkeep` yang terlewat).
  **Mitigation**: Pastikan script pencarian folder kosong hanya menghapus direktori yang benar-benar tidak memiliki file di dalamnya.
