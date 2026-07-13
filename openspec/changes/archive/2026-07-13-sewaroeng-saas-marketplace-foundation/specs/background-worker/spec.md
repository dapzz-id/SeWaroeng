## ADDED Requirements

### Requirement: Order Status Automation Worker
Sistem SHALL memiliki background worker yang secara periodik memeriksa dan mengupdate status order berdasarkan kondisi bisnis (misal: auto-complete order yang DELIVERED lebih dari 3 hari tanpa konfirmasi customer).

#### Scenario: Auto-complete delivered orders
- **WHEN** worker berjalan dan menemukan order dengan status DELIVERED yang updated_at > 3 hari lalu
- **THEN** worker mengupdate status ke COMPLETED, membuat record komisi untuk tenant (jika ada), dan mengirim notifikasi ke customer

### Requirement: Tracking Number Publication
Setelah kurir (Kurir Toko atau kurir pihak ketiga) mengisi nomor resi, sistem SHALL mengirimkan notifikasi ke customer melalui email dan/atau push notification.

#### Scenario: Tracking number notified
- **WHEN** Staff mengisi nomor resi pada order dengan status PROCESSING
- **THEN** status berubah ke SHIPPED, background worker mengirim email/push dengan nomor resi ke customer

### Requirement: PDF Invoice Generation
Sistem SHALL secara otomatis menghasilkan dokumen PDF faktur untuk setiap order (fisik maupun digital) setelah pembayaran dikonfirmasi. PDF disimpan di object storage (S3-compatible) dan URL disertakan dalam notifikasi.

#### Scenario: PDF invoice generated after payment
- **WHEN** webhook pembayaran dikonfirmasi
- **THEN** background worker menghasilkan PDF invoice menggunakan go-pdf/fpdf, mengupload ke storage, menyimpan URL di tabel order_documents

#### Scenario: PDF transaction proof for digital product
- **WHEN** digital product fulfillment berhasil
- **THEN** background worker menghasilkan PDF bukti transaksi yang menyertakan SN yang sudah didekripsi, mengupload ke storage

### Requirement: Email Notification Worker
Background worker SHALL mengirimkan email transaksional (order confirmation, payment confirmation, shipping update, digital fulfillment) menggunakan SMTP dengan template HTML.

#### Scenario: Order confirmation email sent
- **WHEN** order berhasil dibuat
- **THEN** worker mengirimkan email konfirmasi ke customer berisi detail order, total harga, dan instruksi pembayaran
