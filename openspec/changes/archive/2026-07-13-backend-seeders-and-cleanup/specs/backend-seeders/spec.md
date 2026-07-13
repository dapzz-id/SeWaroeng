## ADDED Requirements

### Requirement: Database seeder mengisi data awal untuk development

Sistem backend SHALL menyediakan perintah database seeder yang mengisi data dummy awal ke database PostgreSQL untuk keperluan development dan testing. Data yang dibuat harus mencakup tenant, user (owner, staff, customer), product category, dan product.

#### Scenario: Seeder berhasil mengisi data awal
- **WHEN** developer menjalankan perintah `go run artisan.go db:seed`
- **THEN** database harus terisi dengan:
  - Minimal 1 tenant dengan status 'active'
  - Minimal 1 store terkait tenant tersebut
  - Minimal 1 user dengan role 'owner' terkait tenant
  - Minimal 1 user dengan role 'customer'
  - Minimal 1 product category terkait tenant
  - Minimal 3 product terkait tenant dan category

#### Scenario: Seeder idempotent (dapat dijalankan ulang)
- **WHEN** developer menjalankan seeder lebih dari satu kali
- **THEN** seeder tidak boleh membuat data duplikat (menggunakan upsert atau cek keberadaan data)

### Requirement: Backend tidak memiliki folder kosong yang tidak berguna

Struktur folder backend SHALL bersih dari direktori kosong yang tidak memiliki file. Folder kosong yang merupakan placeholder untuk fitur masa depan (seperti `database/seeders`) boleh diisi atau dihapus sesuai kebutuhan.

#### Scenario: Semua folder kosong dihapus atau diisi
- **WHEN** developer memeriksa seluruh direktori `backend/`
- **THEN** tidak ada direktori kosong kecuali yang memang diperlukan oleh framework
