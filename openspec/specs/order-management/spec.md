## ADDED Requirements

### Requirement: Order Creation with ACID Transaction
Pembuatan order MUST dilakukan dalam satu Database Transaction yang mencakup: validasi produk & stok, pemotongan stok sementara (reserved), pembuatan record order dan order_items, inisiasi pembayaran. Jika salah satu langkah gagal, seluruh transaksi di-ROLLBACK.

#### Scenario: Order created successfully
- **WHEN** customer mengirimkan POST /api/v1/orders dengan cart items valid, alamat pengiriman, dan metode pembayaran
- **THEN** sistem membuka DB transaction, reserve stok, buat order (UUID id) + order_items, inisiasi payment, COMMIT; return 201 dengan order detail dan payment instructions

#### Scenario: Order creation fails - full rollback
- **WHEN** proses inisiasi pembayaran gagal setelah stok sudah di-reserve
- **THEN** DB transaction di-ROLLBACK, stok dikembalikan, order tidak tersimpan

### Requirement: Order Status State Machine
Order MUST mengikuti state machine yang ketat: PENDING_PAYMENT -> PAYMENT_CONFIRMED -> PROCESSING -> SHIPPED -> DELIVERED -> COMPLETED. Transisi ilegal (misal: dari DELIVERED ke PENDING_PAYMENT) harus ditolak.

#### Scenario: Valid status transition
- **WHEN** Staff mengupdate order status dari PROCESSING ke SHIPPED dengan nomor resi
- **THEN** sistem mengupdate status dan menyimpan nomor resi; background worker mengirim notifikasi ke customer

#### Scenario: Invalid status transition rejected
- **WHEN** sistem mencoba mengubah status order COMPLETED ke PROCESSING
- **THEN** sistem mengembalikan error INVALID_STATE_TRANSITION

### Requirement: Order Cancellation with Stock Restoration
Customer MUST dapat membatalkan order yang masih dalam status PENDING_PAYMENT. Pembatalan MUST mengembalikan stok yang di-reserve dalam DB Transaction.

#### Scenario: Customer cancels pending order
- **WHEN** customer mengirimkan POST /api/v1/orders/{id}/cancel untuk order PENDING_PAYMENT
- **THEN** sistem membuka DB transaction, mengembalikan stok tiap item, update status ke CANCELLED, COMMIT
