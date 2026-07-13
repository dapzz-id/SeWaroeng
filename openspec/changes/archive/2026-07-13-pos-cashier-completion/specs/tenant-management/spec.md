## MODIFIED Requirements

### Requirement: Polymorphic Seller Roles & Dynamic KYC
Sistem MUST mendukung klasifikasi tipe toko (Store Type / Seller Sub-role) seperti `warung`, `coffeeshop`, `grosir`, dan `minimarket`. Pendaftaran Seller MUST menyesuaikan persyaratan dokumen (KYC) secara dinamis sesuai dengan tipe toko yang dipilih.

#### Scenario: Registering as a Warung
- **WHEN** user mendaftar sebagai Seller dengan tipe `warung`
- **THEN** sistem hanya mewajibkan unggah dokumen KTP, tanpa meminta NIB.

#### Scenario: Registering as a Coffeeshop
- **WHEN** user mendaftar sebagai Seller dengan tipe `coffeeshop`
- **THEN** sistem mewajibkan unggah dokumen KTP dan NIB (Nomor Induk Berusaha).

### Requirement: Seat Booking System
Aplikasi MUST mengizinkan `Buyer` melakukan reservasi tempat/meja pada toko yang mendukung fitur booking (khususnya tipe `coffeeshop` atau resto).

#### Scenario: Buyer books a seat
- **WHEN** Buyer memilih jam, tanggal, dan jumlah orang pada halaman profil toko (Coffeeshop)
- **THEN** sistem membuat record Booking berstatus `PENDING` dan mengirim notifikasi reservasi ke Seller untuk dikonfirmasi.

### Requirement: Real-time Crowd Level Indicator
Sistem MUST menghitung indikator tingkat keramaian suatu tempat dan menampilkannya kepada `Buyer` agar mereka dapat mempertimbangkan sebelum datang.

#### Scenario: Crowd level is low
- **WHEN** jumlah pengunjung aktif (transaksi POS aktif + booking berjalan) berada di bawah 40% dari kapasitas maksimal toko
- **THEN** Buyer melihat indikator berwarna Hijau (Sepi) di profil toko.

#### Scenario: Crowd level is high
- **WHEN** jumlah pengunjung aktif mendekati kapasitas maksimal toko (> 80%)
- **THEN** Buyer melihat indikator berwarna Merah (Sangat Ramai) di profil toko.
