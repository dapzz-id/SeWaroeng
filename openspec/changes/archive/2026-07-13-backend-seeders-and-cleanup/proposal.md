## Why

Sistem backend (Goravel) saat ini belum memiliki database seeder yang memadai untuk mengisi data dummy awal (seperti data dummy tenant, user, product, dll) yang berguna untuk development dan testing. Selain itu, perlu dilakukan pembersihan (cleanup) pada backend untuk menghapus folder-folder kosong yang tidak digunakan agar struktur folder lebih rapi, serupa dengan yang telah dilakukan pada frontend.

## What Changes

- **Add Seeders**: Menambahkan skrip seeder pada Goravel backend untuk mengisi data dummy awal (seperti roles, users, tenants, produk).
- **Backend Cleanup**: Memeriksa seluruh direktori backend dan menghapus folder-folder kosong yang tidak digunakan.

## Capabilities

### New Capabilities

### Modified Capabilities

## Impact

- Memudahkan developer baru untuk melakukan setup database dan mendapatkan data awal untuk pengetesan.
- Struktur folder backend menjadi lebih bersih dan rapi.
