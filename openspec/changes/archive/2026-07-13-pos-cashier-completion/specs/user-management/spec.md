## MODIFIED Requirements

### Requirement: Role-Based Access Control (RBAC) & Feature Access
Sistem MUST mendukung 3 level hierarki peran (Superadmin, Seller, Buyer) yang mengatur kontrol akses ke fitur-fitur spesifik aplikasi melalui Middleware dan UI Conditional Rendering.

#### Scenario: Buyer Access
- **WHEN** user dengan role `Buyer` login
- **THEN** user mendapat akses untuk order item, melihat Peta Lokasi (Maps) untuk menemukan UMKM/Warung terdekat, melihat profil pemilik warung & kontak, serta dapat mengirim Direct Message ke warung tersebut.

#### Scenario: Superadmin Global Monitoring
- **WHEN** user dengan role `Superadmin` login
- **THEN** sistem menampilkan dashboard aggregasi tingkat sistem (rata-rata pendapatan app, total pengguna, peta lokasi semua warung).

#### Scenario: Superadmin Administrative Actions
- **WHEN** Superadmin melihat detail sebuah Warung/Seller
- **THEN** Superadmin dapat melihat (Read-Only) data laba bersih/laba kotor warung tersebut, dan berhak melakukan tindakan administratif seperti Suspend atau Banned.

#### Scenario: Superadmin Push Notifications & Promos
- **WHEN** Superadmin mengakses panel notifikasi
- **THEN** Superadmin dapat membuat *Global Notification* (seperti info maintenance aplikasi) dan mengatur *Event/Kode Promo* yang berlaku secara platform-wide.

#### Scenario: Seller (UMKM) Access
- **WHEN** user dengan role `Seller` login
- **THEN** user mendapat akses eksklusif ke modul Kasir (POS), Manajemen Stok Produk, Laporan Keuangan UMKM, dan Analitik AI Gemini.
