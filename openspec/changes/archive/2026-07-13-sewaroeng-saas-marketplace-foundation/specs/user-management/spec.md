## ADDED Requirements

### Requirement: User Roles per Tenant
Sistem SHALL mendukung tiga role pengguna dalam konteks tenant: Owner (akses penuh), Staff (akses operasional terbatas), dan Customer (pembeli). Role MUST dikontrol via RBAC middleware.

#### Scenario: Owner manages staff
- **WHEN** Owner mengirimkan POST /api/v1/users/staff dengan data staff baru
- **THEN** sistem membuat user baru dengan role Staff, terikat pada tenant_id Owner

#### Scenario: Staff cannot access owner-only endpoints
- **WHEN** Staff mencoba mengakses endpoint yang memerlukan role Owner (misal: konfigurasi payment gateway)
- **THEN** sistem mengembalikan 403 Forbidden

### Requirement: Customer Registration and Profile
Customer SHALL dapat mendaftar ke platform (bukan per-toko) dan berbelanja di berbagai toko SeWaroeng. Data profil customer: nama, email, nomor HP, alamat pengiriman (multiple).

#### Scenario: Customer registers
- **WHEN** calon customer mengirimkan POST /api/v1/auth/register dengan data valid
- **THEN** sistem membuat user baru dengan role Customer, mengirim email verifikasi

#### Scenario: Customer manages multiple addresses
- **WHEN** customer menambahkan alamat baru via POST /api/v1/customers/addresses
- **THEN** sistem menyimpan alamat dengan UUID id, terhubung ke customer via customer_id FK

### Requirement: User Soft Delete
User MUST menggunakan soft delete (kolom deleted_at TIMESTAMP NULL). Data user yang dihapus tetap ada di database untuk keperluan audit order history.

#### Scenario: User soft deleted
- **WHEN** Owner menghapus akun Staff via DELETE /api/v1/users/{id}
- **THEN** sistem mengisi kolom deleted_at dengan timestamp saat ini; user tidak dapat login
