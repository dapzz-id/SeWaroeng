## ADDED Requirements

### Requirement: POS Transaction Creation
Modul Kasir SHALL memungkinkan Staff/Owner membuat transaksi penjualan offline langsung dari aplikasi tanpa melalui flow order online.

#### Scenario: POS transaction created
- **WHEN** Staff mengirimkan POST /api/v1/pos/transactions dengan daftar item, jumlah bayar, dan metode (CASH/QRIS_OFFLINE)
- **THEN** sistem membuat transaksi POS, memotong stok produk dalam DB Transaction, menghitung kembalian, mengembalikan receipt data

#### Scenario: POS transaction with change calculation
- **WHEN** customer membayar Rp 100.000 untuk total belanja Rp 75.000
- **THEN** sistem menghitung kembalian Rp 25.000 dan menyertakannya dalam response

### Requirement: POS Offline Sync
Aplikasi Flutter POS SHALL mendukung mode offline menggunakan local database (Hive). Transaksi yang dibuat offline MUST disinkronkan ke server saat koneksi tersedia.

#### Scenario: POS works offline
- **WHEN** koneksi internet tidak tersedia
- **THEN** aplikasi menyimpan transaksi di Hive local DB dengan status PENDING_SYNC

#### Scenario: POS syncs when online
- **WHEN** koneksi internet pulih
- **THEN** aplikasi mengirimkan semua transaksi PENDING_SYNC ke server; server memproses dan mengembalikan konfirmasi

### Requirement: POS Receipt Generation
Setiap transaksi POS MUST dapat menghasilkan struk belanja dalam format yang dapat dicetak (thermal printer 58mm/80mm).

#### Scenario: Receipt data returned
- **WHEN** transaksi POS berhasil dibuat
- **THEN** sistem mengembalikan data receipt terstruktur yang dapat dirender oleh aplikasi atau dikirim ke printer Bluetooth
