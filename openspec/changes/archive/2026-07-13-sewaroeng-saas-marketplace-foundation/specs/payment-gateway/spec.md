## ADDED Requirements

### Requirement: Payment Gateway Interface
Sistem SHALL mendefinisikan Go interface PaymentGatewayProvider yang diimplementasikan oleh setiap gateway. Pemilihan gateway dilakukan saat runtime berdasarkan metode pembayaran yang dipilih customer.

#### Scenario: Gateway selected at runtime
- **WHEN** order dibuat dengan metode pembayaran QRIS
- **THEN** sistem menggunakan implementasi MidtransProvider secara otomatis

#### Scenario: New gateway added without breaking existing code
- **WHEN** developer mengimplementasikan interface PaymentGatewayProvider untuk gateway baru
- **THEN** gateway dapat diregistrasikan dan digunakan tanpa modifikasi pada service layer bestehende

### Requirement: Midtrans Snap Integration
Sistem SHALL mengintegrasikan Midtrans Snap API untuk menerima pembayaran QRIS dan Virtual Account.

#### Scenario: Midtrans payment initiated
- **WHEN** order dibuat dengan metode Midtrans
- **THEN** sistem memanggil Midtrans Snap API, mendapatkan payment token, mengembalikan Snap URL/token ke client

#### Scenario: Midtrans webhook received and verified
- **WHEN** Midtrans mengirimkan webhook notification ke POST /api/v1/webhooks/midtrans
- **THEN** sistem memverifikasi signature hash (SHA512 dari order_id + status_code + gross_amount + server_key), update status order jika signature valid

### Requirement: PayPal REST API Integration
Sistem SHALL mengintegrasikan PayPal REST API untuk pembayaran dalam USD.

#### Scenario: PayPal order created
- **WHEN** customer memilih PayPal sebagai metode pembayaran
- **THEN** sistem membuat PayPal Order via REST API, mendapatkan approval URL, redirect customer ke PayPal

#### Scenario: PayPal payment captured after approval
- **WHEN** customer menyetujui pembayaran di PayPal dan redirect ke callback URL
- **THEN** sistem menangkap payment via PayPal Capture API, update status order

### Requirement: Wise Webhook Integration
Sistem SHALL mengintegrasikan Wise Webhook untuk menerima notifikasi transfer internasional (GBP/EUR/USD).

#### Scenario: Wise webhook signature validated
- **WHEN** Wise mengirimkan webhook event ke POST /api/v1/webhooks/wise
- **THEN** sistem memverifikasi signature menggunakan Wise public key (RSA-SHA256), proses event jika valid

### Requirement: COD Payment Support
Sistem SHALL mendukung metode pembayaran Cash on Delivery (COD) di mana konfirmasi pembayaran dilakukan manual oleh kurir/staff.

#### Scenario: COD order confirmed by staff
- **WHEN** Staff mengirimkan POST /api/v1/orders/{id}/confirm-cod setelah customer membayar tunai
- **THEN** sistem mengupdate status pembayaran ke PAID dan status order ke PROCESSING
