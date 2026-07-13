## ADDED Requirements

### Requirement: Wallet Balance per User
Setiap user (customer maupun tenant) SHALL memiliki satu wallet account dengan saldo dalam IDR. Semua operasi wallet (kredit/debit) MUST dilakukan dalam DB Transaction untuk memastikan ACID compliance.

#### Scenario: Wallet auto-created on registration
- **WHEN** user baru berhasil mendaftar
- **THEN** sistem secara otomatis membuat wallet record dengan saldo 0 dan UUID wallet_id

#### Scenario: Wallet top-up via payment gateway
- **WHEN** user melakukan top-up wallet via Midtrans
- **THEN** setelah webhook konfirmasi diterima, sistem membuka DB Transaction, menambahkan saldo wallet, membuat record wallet_transactions, COMMIT

### Requirement: Wallet as Payment Method
Customer SHALL dapat menggunakan saldo wallet sebagai metode pembayaran untuk order di SeWaroeng.

#### Scenario: Wallet payment for order
- **WHEN** customer checkout dengan metode WALLET dan saldo mencukupi
- **THEN** sistem membuka DB Transaction, SELECT FOR UPDATE pada wallet, mendebit saldo, membuat order, membuat wallet_transaction, COMMIT

#### Scenario: Insufficient wallet balance
- **WHEN** customer mencoba checkout dengan saldo wallet lebih kecil dari total order
- **THEN** sistem mengembalikan 402 Payment Required dengan error INSUFFICIENT_WALLET_BALANCE, DB Transaction di-ROLLBACK

### Requirement: Wallet Transaction History
User SHALL dapat melihat riwayat semua transaksi wallet (top-up, pembayaran, refund) dengan pagination.

#### Scenario: Transaction history paginated
- **WHEN** user mengakses GET /api/v1/wallet/transactions?page=1&limit=20
- **THEN** sistem mengembalikan 20 transaksi terbaru dengan metadata pagination
