## ADDED Requirements

### Requirement: Digital Product Provider Integration
Sistem SHALL menyediakan interface abstrak untuk menghubungkan API penyedia PPOB pihak ketiga (pulsa, paket data, token listrik, BPJS, top-up dompet digital, voucher game).

#### Scenario: Provider interface implemented
- **WHEN** developer mengimplementasikan interface DigitalProductProvider baru
- **THEN** provider dapat terdaftar di registry dan digunakan tanpa mengubah core business logic

#### Scenario: Product inquiry before purchase
- **WHEN** customer melakukan inquiry nomor tujuan (misal: nomor HP untuk pulsa)
- **THEN** sistem memanggil provider API untuk validasi nomor dan mengembalikan info produk (nama operator, harga)

### Requirement: Digital Product Fulfillment
Setelah pembayaran lunas (webhook konfirmasi), sistem MUST secara otomatis memproses fulfillment produk digital dalam Background Worker: memanggil provider API, menyimpan Serial Number (SN) di tabel digital_transactions, dan mengirim notifikasi ke customer.

#### Scenario: Successful digital fulfillment
- **WHEN** webhook pembayaran dikonfirmasi untuk order produk digital
- **THEN** background worker memanggil provider API, menyimpan SN di tabel digital_transactions, update status order ke COMPLETED, kirim notifikasi customer (email/push)

#### Scenario: Fulfillment failure with retry
- **WHEN** panggilan provider API gagal (timeout/error)
- **THEN** Goravel Queue me-retry job dengan exponential backoff (max 3x); setelah max retry, order status = FAILED dan customer direfund via wallet

### Requirement: Serial Number Storage
Setiap transaksi produk digital yang berhasil MUST menyimpan SN/kode voucher di tabel digital_transactions dengan enkripsi AES-256-GCM.

#### Scenario: SN stored encrypted
- **WHEN** fulfillment berhasil mendapatkan SN dari provider
- **THEN** SN dienkripsi dengan AES-256-GCM menggunakan key dari environment variable sebelum disimpan ke database

#### Scenario: SN accessible by customer
- **WHEN** customer mengakses GET /api/v1/orders/{order_id}/digital-result
- **THEN** sistem mendekripsi SN dan mengembalikannya dalam response (hanya untuk order milik customer tersebut)
