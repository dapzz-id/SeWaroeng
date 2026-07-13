# API Documentation - SeWaroeng

Base URL: `http://localhost:3000/api/v1`

## Otentikasi & Otorisasi
Sebagian besar endpoint dilindungi menggunakan **JSON Web Token (JWT)**.
Kirim token pada HTTP Header:
`Authorization: Bearer <access_token>`

## Public Endpoints
- `GET /public/stores` : Daftar toko/tenant yang aktif.
- `GET /public/stores/{id}/products` : Katalog produk publik untuk toko tertentu.
- `GET /public/products/{id}` : Detail produk.

## Auth Endpoints
- `POST /auth/register` : Registrasi akun pengguna baru.
- `POST /auth/login` : Login akun (mengembalikan access_token).
- `POST /auth/refresh` : Memperbarui access_token menggunakan refresh token (dikirim via cookie).
- `DELETE /auth/logout` : Menghapus session saat ini.
- `GET /auth/sessions` : Mendapatkan daftar sesi aktif untuk akun tersebut di semua perangkat.
- `DELETE /auth/sessions/{id}` : Memaksa logout perangkat lain.

## Protected Endpoints (Requires Auth)

### Wallet
- `GET /wallet` : Cek saldo e-wallet pengguna.
- `POST /wallet/topup` : Isi ulang saldo dompet.
- `GET /wallet/transactions` : Riwayat transaksi dompet (credit/debit).

### Products (Toko / Tenant)
- `POST /products` : Tambah produk baru (physical / digital).
- `PUT /products/{id}` : Update informasi produk.
- `DELETE /products/{id}` : Soft delete produk (Ubah status ke archived).

### Orders
- `POST /orders` : Buat pesanan baru. Mendukung pengecekan stok real-time.
- `GET /orders` : Riwayat pesanan (difilter berdasarkan pembeli atau tenant).
- `POST /orders/{id}/cancel` : Batalkan pesanan dan kembalikan stok.
- `PUT /orders/{id}/status` : Update status pengiriman (termasuk isi nomor resi kurir).

### POS (Point of Sales)
- `POST /pos/transactions` : Transaksi offline-kasir langsung potong stok.
- `POST /pos/sync` : Endpoint batch sinkronisasi dari Flutter (Hive).

### Digital Products (PPOB)
- `POST /digital/inquiry` : Cek ketersediaan produk digital dan tarif (mis: Pulsa/PLN).

### Shipping / Kurir Toko
- `POST /shipping/calculate` : Kalkulasi tarif ongkos kirim berdasarkan latitude & longitude tujuan.
- `POST /store/couriers` : Daftarkan staf kurir internal toko.

## Webhooks (No JWT required, Signature Checked)
- `POST /webhooks/midtrans`
- `POST /webhooks/paypal`
- `POST /webhooks/wise`
Mengupdate status pembayaran pesanan secara asinkron.
